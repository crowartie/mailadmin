// Вложения: что умеет показывать просмотрщик и как собрать для него список.
// Картинки и PDF — как есть; офисные документы сервер на лету переводит в PDF (LibreOffice).
import { api } from './api';

export const OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];
// Без точки в имени расширения нет: раньше файл, названный «doc» или «rtf», сам себя
// объявлял офисным документом, уходил на преобразование и показывался пустым кадром.
export const ext = (a) => {
    const n = String(a.name || '').toLowerCase();
    const i = n.lastIndexOf('.');
    return i > 0 && i < n.length - 1 ? n.slice(i + 1) : '';
};
export const isImg = (a) => String(a.type || '').startsWith('image/');
export const isPdf = (a) => a.type === 'application/pdf' || ext(a) === 'pdf';
export const isOffice = (a) => OFFICE.includes(ext(a));

// Пустое вложение: отправитель объявил файл, но тела не прислал.
// Так делают мобильные клиенты, когда связь рвётся на полуслове: в письме остаются
// заголовки части с «size=0», а внутри — один перевод строки. Поэтому порог не ноль:
// два байта — это уже только перевод строки, полезного файла такого размера не бывает.
export const EMPTY_MAX = 2;
export const isEmpty = (a) => Number(a.size ?? 0) <= EMPTY_MAX;

// Показывать нечего — ни картинку, ни PDF: смотреть пустоту предлагать не надо.
export const viewable = (a) => !a.inline && !isEmpty(a) && (isImg(a) || isPdf(a) || isOffice(a));

export function viewUrl(folder, uid, a) {
    return isOffice(a) ? api.attachmentPreviewUrl(folder, uid, a.index) : api.attachmentUrl(folder, uid, a.index, true);
}

/** Элементы просмотрщика для вложений письма (только те, что можно показать). */
export function viewerItems(folder, uid, list) {
    return list.filter(viewable).map((x) => ({
        url: viewUrl(folder, uid, x), downloadUrl: api.attachmentUrl(folder, uid, x.index),
        name: x.name, type: isImg(x) ? x.type : 'application/pdf', size: x.size, converted: isOffice(x),
    }));
}

/** Локальный файл (ещё не отправленный) — картинка или PDF; blob: запрещён CSP, поэтому data:. */
export const localViewable = (f) => String(f.type || '').startsWith('image/') || f.type === 'application/pdf' || /\.pdf$/i.test(f.name || '');
export function readAsDataUrl(file) {
    return new Promise((resolve, reject) => {
        const r = new FileReader();
        r.onload = () => resolve(String(r.result || ''));
        r.onerror = reject;
        r.readAsDataURL(file);
    });
}
/**
 * Элементы просмотрщика для ещё не отправленных файлов. Раньше при открытии одного файла
 * в память читались сразу все: на нескольких крупных вкладка надолго замирала.
 * Читаем только тот, который смотрят; остальные подгрузит сам просмотрщик при листании.
 */
export async function localViewerItems(files, only = null) {
    const list = files.filter(localViewable);

    return Promise.all(list.map(async (f, i) => {
        const base = { name: f.name, type: String(f.type || '').startsWith('image/') ? f.type : 'application/pdf', size: f.size, file: f };
        if (only !== null && i !== only) {
            return { ...base, url: '', downloadUrl: '' };
        }
        try {
            const url = await readAsDataUrl(f);

            return { ...base, url, downloadUrl: url };
        } catch {
            // 155: отказ чтения уходил в необработанную ошибку, а просмотрщик падал на имени файла.
            return { ...base, url: '', downloadUrl: '', error: 'Файл не удалось прочитать — возможно, его переместили или удалили' };
        }
    }));
}
