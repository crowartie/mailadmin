// Вложения: что умеет показывать просмотрщик и как собрать для него список.
// Картинки и PDF — как есть; офисные документы сервер на лету переводит в PDF (LibreOffice).
import { api } from './api';

// Что показывает просмотрщик и каким способом (обращение №64). Сервер переводит в PDF офисные документы,
// сканы TIFF и чертежи DXF/DWG (OfficePdf), фото с iPhone HEIC — в JPEG (ImagePreview), таблицы — таблицей
// (SheetPreview). Картинки, PDF, видео, звук, SVG и текст браузер показывает сам.
export const OFFICE = [
    'doc', 'docx', 'docm', 'dot', 'dotx', 'dotm', 'rtf', 'odt', 'ott',
    'xls', 'xlsx', 'xlsm', 'xlsb', 'xltx', 'xltm', 'ods', 'ots', 'csv',
    'ppt', 'pptx', 'pptm', 'pps', 'ppsx', 'pot', 'potx', 'odp', 'otp', 'odg',
    'vsd', 'vsdx', 'pub', 'cdr', 'pages', 'numbers', 'key',
    'tif', 'tiff', 'dxf', 'dwg',
];
export const CAD = ['dxf', 'dwg'];
export const HEIC = ['heic', 'heif'];
export const SVG = ['svg'];
export const TEXT = ['txt', 'log', 'xml', 'json', 'md', 'ini', 'cfg', 'conf', 'yml', 'yaml', 'sql', 'bat', 'cmd', 'ps1', 'sh', 'reg', 'nfo'];
export const VIDEO = ['mp4', 'm4v', 'mov', 'webm', 'ogv'];
export const AUDIO = ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'oga', 'opus', 'flac'];
export const TEXT_MAX = 2 * 1024 * 1024;   // больше — это уже не «прочитать глазами», а скачать
// Без точки в имени расширения нет: раньше файл, названный «doc» или «rtf», сам себя
// объявлял офисным документом, уходил на преобразование и показывался пустым кадром.
export const ext = (a) => {
    const n = String(a.name || '').toLowerCase();
    const i = n.lastIndexOf('.');
    return i > 0 && i < n.length - 1 ? n.slice(i + 1) : '';
};
const typeOf = (a) => String(a.type || '').toLowerCase();
export const isHeic = (a) => HEIC.includes(ext(a)) || /^image\/hei[cf]/.test(typeOf(a));
export const isSvg = (a) => SVG.includes(ext(a)) || typeOf(a) === 'image/svg+xml';
// Картинка, которую браузер покажет сам. TIFF и HEIC — нет (их переводит сервер), SVG — отдельно.
export const isImg = (a) => typeOf(a).startsWith('image/') && !isHeic(a) && !isSvg(a) && !['tif', 'tiff'].includes(ext(a)) && typeOf(a) !== 'image/tiff';
export const isPdf = (a) => typeOf(a) === 'application/pdf' || ext(a) === 'pdf';
export const isOffice = (a) => OFFICE.includes(ext(a)) || typeOf(a) === 'image/tiff';
// Таблицы показываем таблицей (SheetPreview на сервере), «как при печати» — тем же PDF, что у документов.
export const SHEET = ['xls', 'xlsx', 'xlsm', 'xltx', 'xltm', 'ods', 'csv'];
export const isSheet = (a) => SHEET.includes(ext(a));
export const isText = (a) => TEXT.includes(ext(a)) && Number(a.size ?? 0) <= TEXT_MAX;
export const isVideo = (a) => VIDEO.includes(ext(a)) || typeOf(a).startsWith('video/');
export const isAudio = (a) => AUDIO.includes(ext(a)) || typeOf(a).startsWith('audio/');
// Письмо, приложенное к письму: показываем его отдельным окном, а не просмотрщиком картинок.
export const isEml = (a) => typeOf(a) === 'message/rfc822' || ext(a) === 'eml';

// Пустое вложение: отправитель объявил файл, но тела не прислал.
// Так делают мобильные клиенты, когда связь рвётся на полуслове: в письме остаются
// заголовки части с «size=0», а внутри — один перевод строки. Поэтому порог не ноль:
// два байта — это уже только перевод строки, полезного файла такого размера не бывает.
export const EMPTY_MAX = 2;
export const isEmpty = (a) => Number(a.size ?? 0) <= EMPTY_MAX;

/** Способ показа: image | heic | svg | pdf | sheet | text | video | audio; null — только скачать. */
export function kindOf(a) {
    if (isSheet(a)) return 'sheet';
    if (isHeic(a)) return 'heic';
    if (isSvg(a)) return 'svg';
    if (isImg(a)) return 'image';
    if (isPdf(a) || isOffice(a)) return 'pdf';
    if (isVideo(a)) return 'video';
    if (isAudio(a)) return 'audio';
    if (isText(a)) return 'text';
    return null;
}

// Показывать нечего — ни картинку, ни PDF: смотреть пустоту предлагать не надо.
export const viewable = (a) => !a.inline && !isEmpty(a) && !isEml(a) && kindOf(a) !== null;

/** Один элемент просмотрщика: адреса для показа и для «Скачать», тип для отрисовки. */
function item(x, urls) {
    const kind = kindOf(x);
    const type = kind === 'image' ? x.type : kind === 'heic' ? 'image/jpeg' : kind === 'svg' ? 'image/svg+xml' : kind === 'pdf' || kind === 'sheet' ? 'application/pdf' : x.type;
    return {
        url: urls.view(kind), downloadUrl: urls.download, name: x.name, type, kind, size: x.size,
        converted: kind === 'pdf' && !isPdf(x) || kind === 'sheet' || kind === 'heic',
        sheetUrl: kind === 'sheet' ? urls.sheet : null,
        cad: CAD.includes(ext(x)),
    };
}

export function viewUrl(folder, uid, a) {
    const k = kindOf(a);
    if (k === 'pdf' && !isPdf(a) || k === 'sheet') return api.attachmentPreviewUrl(folder, uid, a.index);
    if (k === 'heic') return api.attachmentImagePreviewUrl(folder, uid, a.index);
    return api.attachmentUrl(folder, uid, a.index, true);
}

/** Элементы просмотрщика для вложений письма (только те, что можно показать). */
export function viewerItems(folder, uid, list) {
    return list.filter(viewable).map((x) => item(x, {
        view: () => viewUrl(folder, uid, x),
        download: api.attachmentUrl(folder, uid, x.index),
        sheet: api.attachmentSheetUrl(folder, uid, x.index),
    }));
}

/**
 * Файлы из своего хранилища (карточки в письме). Файлы, лежащие в Nextcloud, сервер не переводит —
 * у них копии на диске нет: показываем только то, что браузер умеет сам.
 */
export function fileViewable(f) {
    if (!f.preview || f.expired) return false;
    const k = kindOf(f);
    return f.cloud ? ['image', 'svg', 'video', 'audio', 'text'].includes(k) || isPdf(f) : k !== null;
}
export function fileViewerItems(list) {
    return list.filter(fileViewable).map((x) => item(x, {
        view: (k) => (k === 'pdf' && !isPdf(x) || k === 'sheet' ? api.filePreviewUrl(x.token) : k === 'heic' ? api.fileImagePreviewUrl(x.token) : api.fileContentUrl(x.token)),
        download: x.url,
        sheet: api.fileSheetUrl(x.token),
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
