// Форматирование дат, размеров, инициалов — одно место на весь интерфейс.
const DAYS = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];

export function when(iso, long = false) {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const now = new Date();
    const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    if (long) {
        return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' }) + ', ' + time;
    }
    if (d.toDateString() === now.toDateString()) return time;
    const yesterday = new Date(now); yesterday.setDate(now.getDate() - 1);
    if (d.toDateString() === yesterday.toDateString()) return 'вчера';
    const diff = (now - d) / 86400000;
    if (diff < 6) return DAYS[d.getDay()];
    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: d.getFullYear() === now.getFullYear() ? undefined : '2-digit' }).replace('.', '');
}

export function size(bytes) {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return `${bytes} Б`;
    if (bytes < 1048576) return `${Math.round(bytes / 1024)} КБ`;
    return `${(bytes / 1048576).toFixed(1).replace('.0', '')} МБ`;
}

export function initials(name, mail) {
    const src = (name && name !== mail ? name : (mail || '')).replace(/["<>]/g, '').trim();
    if (!src) return '·';
    const parts = src.split(/[\s@._-]+/).filter(Boolean);
    const s = parts.length >= 2 ? parts[0][0] + parts[1][0] : src.slice(0, 2);
    return s.toUpperCase();
}

export function plural(n, one, few, many) {
    const m10 = n % 10; const m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
    return many;
}

export function addrList(list) {
    return (list || []).map((a) => (a.name && a.name !== a.mail ? a.name : a.mail)).join(', ');
}

export function addrString(list) {
    return (list || []).map((a) => (a.name && a.name !== a.mail ? `${a.name} <${a.mail}>` : a.mail)).join(', ');
}

export function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

/** Локальная дата → строка для input[type=datetime-local]. */
export function toLocalInput(d) {
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

/** Готовые варианты «отложить / отправить позже». */
export function presets() {
    const now = new Date();
    const at = (d, h) => { const x = new Date(d); x.setHours(h, 0, 0, 0); return x; };
    const tomorrow = new Date(now); tomorrow.setDate(now.getDate() + 1);
    const monday = new Date(now); monday.setDate(now.getDate() + ((8 - now.getDay()) % 7 || 7));
    const week = new Date(now); week.setDate(now.getDate() + 7);
    const out = [];
    if (now.getHours() < 17) out.push({ label: 'Сегодня вечером', sub: '18:00', at: at(now, 18) });
    out.push({ label: 'Завтра утром', sub: '09:00', at: at(tomorrow, 9) });
    out.push({ label: 'В понедельник', sub: '09:00', at: at(monday, 9) });
    out.push({ label: 'Через неделю', sub: DAYS[week.getDay()] + ' 09:00', at: at(week, 9) });
    return out;
}
