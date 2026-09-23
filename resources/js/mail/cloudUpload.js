// Загрузка в личное облако частями с докачкой.
//
// Файл режется на части (размер задаёт сервер, 10 МБ), каждая отправляется отдельно. Если связь
// пропала, часть повторяется, пока не пройдёт: ждём события «online» или нарастающую паузу до 30 с.
// Номер загрузки запоминается в localStorage по имени, размеру и дате файла: после перезагрузки
// страницы, выбрав тот же файл снова, человек продолжит с того места, где оборвалось.
//
// Очередь живёт в модуле, а не в странице: уйти в почту во время загрузки можно, она продолжится.
import { reactive } from 'vue';
import { api, xsrf } from './api';

const KEY = 'cloud-uploads-v1';
const PARALLEL = 2;

export const uploads = reactive({ items: [] });

let running = 0;
let seq = 0;

function saved() {
    try { return JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch { return {}; }
}
function save(map) {
    try { localStorage.setItem(KEY, JSON.stringify(map)); } catch { /* приватный режим — просто без докачки после перезагрузки */ }
}
const fileKey = (f, dir) => [dir, f.name, f.size, f.lastModified].join('|');

export function chunkLength(size, chunk, n) {
    const total = Math.max(1, Math.ceil(size / chunk));
    if (n < 1 || n > total) return -1;
    return n < total ? chunk : size - chunk * (total - 1);
}

/** Незаконченные загрузки прошлых сеансов: чтобы продолжить, нужно выбрать тот же файл. */
export function unfinished() {
    const active = new Set(uploads.items.map((i) => i.key));
    return Object.entries(saved()).filter(([k]) => !active.has(k)).map(([key, v]) => ({ key, ...v }));
}

export function forgetUnfinished(key) {
    const map = saved();
    const v = map[key];
    delete map[key];
    save(map);
    if (v?.id) api.cloudUploadAbort(v.id).catch(() => {});
}

export function active() {
    return uploads.items.some((i) => ['queued', 'uploading', 'waiting'].includes(i.state));
}

/** Добавить файлы в очередь. onDone(item) вызывается по каждому готовому файлу. */
export function addUploads(files, dir, onDone) {
    for (const file of files) {
        const key = fileKey(file, dir);
        if (uploads.items.some((i) => i.key === key && i.state !== 'done' && i.state !== 'cancelled')) continue;
        uploads.items.push(reactive({ uid: ++seq, key, file, dir, name: file.name, size: file.size, sent: 0, state: 'queued', error: '', waiting: '', id: null, path: '', rate: 0, onDone, started: 0 }));
    }
    pump();
}

export function cancelUpload(it) {
    const was = it.state;
    it.state = 'cancelled';
    const map = saved();
    delete map[it.key];
    save(map);
    if (it.id && was !== 'done') api.cloudUploadAbort(it.id).catch(() => {});
    uploads.items.splice(uploads.items.indexOf(it), 1);
}

export function retryUpload(it) {
    it.state = 'queued';
    it.error = '';
    pump();
}

export function clearFinished() {
    uploads.items = uploads.items.filter((i) => !['done', 'cancelled'].includes(i.state));
}

function pump() {
    while (running < PARALLEL) {
        const it = uploads.items.find((i) => i.state === 'queued');
        if (!it) return;
        running++;
        run(it).finally(() => { running--; pump(); });
    }
}

async function run(it) {
    it.state = 'uploading';
    it.error = '';
    it.started = Date.now();
    try {
        const map = saved();
        let st = null;
        if (map[it.key]?.id) {
            try { st = await api.cloudUploadStatus(map[it.key].id); } catch (e) {
                if (e.status !== 404) throw e;
                delete map[it.key];
                save(map);
            }
        }
        if (!st) {
            st = await api.cloudUploadStart(it.dir, it.name, it.size);
            map[it.key] = { id: st.id, name: st.name, dir: it.dir, size: it.size, at: Date.now() };
            save(map);
        }
        it.id = st.id;
        it.name = st.name;
        it.path = st.path;
        const have = new Set(st.have);
        const cs = st.chunkSize;
        it.sent = [...have].reduce((s, n) => s + chunkLength(it.size, cs, n), 0);
        const base = it.sent;
        for (let n = 1; n <= st.chunks; n++) {
            if (have.has(n)) continue;
            if (it.state === 'cancelled') return;
            const blob = it.file.slice((n - 1) * cs, Math.min(n * cs, it.size));
            await putChunk(it, n, blob);
            it.sent += blob.size;
            const secs = (Date.now() - it.started) / 1000;
            if (secs > 1) it.rate = (it.sent - base) / secs;
        }
        const r = await api.cloudUploadFinish(it.id);
        const m = saved();
        delete m[it.key];
        save(m);
        it.state = 'done';
        it.item = r.item;
        it.onDone?.(r.item);
    } catch (e) {
        if (it.state === 'cancelled') return;
        it.state = 'error';
        it.error = e.message || 'Не загрузилось';
    }
}

async function putChunk(it, n, blob) {
    let pause = 2000;
    for (;;) {
        if (it.state === 'cancelled') throw new Error('Загрузка отменена');
        let status = 0;
        let msg = '';
        try {
            const r = await fetch(`/mail/api/cloud/uploads/${encodeURIComponent(it.id)}/${n}`, {
                method: 'PUT', body: blob, credentials: 'same-origin',
                headers: { 'X-XSRF-TOKEN': xsrf(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', 'Content-Type': 'application/octet-stream' },
            });
            if (r.ok) { it.state = 'uploading'; it.waiting = ''; return; }
            status = r.status;
            const d = await r.json().catch(() => ({}));
            msg = d.message || '';
        } catch {
            status = 0;
        }
        // Окончательные ошибки: сеанс кончился, загрузку не найти, нет прав. Остальное — повторяем.
        if ([401, 403, 404, 413, 415].includes(status)) throw new Error(msg || `Ошибка ${status}`);
        it.state = 'waiting';
        it.waiting = status === 0 || !navigator.onLine ? 'Нет связи — продолжим с этого места, когда она появится' : (msg || 'Облако не ответило — повторяю');
        await new Promise((res) => {
            const done = () => { clearTimeout(t); window.removeEventListener('online', done); res(); };
            const t = setTimeout(done, pause);
            window.addEventListener('online', done);
        });
        pause = Math.min(pause * 2, 30000);
    }
}

// Уход со страницы во время загрузки: браузер спросит — иначе загрузка молча оборвётся.
if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', (e) => {
        if (active()) { e.preventDefault(); e.returnValue = ''; }
    });
}
