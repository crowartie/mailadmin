// Вложения: что умеет показывать просмотрщик и как собрать для него список.
// Картинки и PDF — как есть; офисные документы сервер на лету переводит в PDF (LibreOffice).
import { api } from './api';

export const OFFICE = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf'];
export const ext = (a) => String(a.name || '').toLowerCase().split('.').pop();
export const isImg = (a) => String(a.type || '').startsWith('image/');
export const isPdf = (a) => a.type === 'application/pdf' || ext(a) === 'pdf';
export const isOffice = (a) => OFFICE.includes(ext(a));
export const viewable = (a) => !a.inline && (isImg(a) || isPdf(a) || isOffice(a));

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
export async function localViewerItems(files) {
    const list = files.filter(localViewable);
    return Promise.all(list.map(async (f) => {
        const url = await readAsDataUrl(f);
        return { url, downloadUrl: url, name: f.name, type: String(f.type || '').startsWith('image/') ? f.type : 'application/pdf', size: f.size };
    }));
}
