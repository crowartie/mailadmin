// Форматирование дат, размеров, инициалов — одно место на весь интерфейс.
const DAYS = ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'];
// Месяцы в родительном падеже: toLocaleDateString с month:'short' добавляет точку и хвост « г.»,
// которые приходилось вычищать заменами — вычищались не полностью («24 окт 25 г.»).
const MONTHS = ['янв', 'фев', 'мар', 'апр', 'мая', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

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
    // diff >= 0: письмо из будущего (сбитые часы отправителя, приём спамеров) не должно
    // показываться днём недели — иначе выглядит как письмо этой недели.
    if (diff >= 0 && diff < 6) return DAYS[d.getDay()];
    const short = `${d.getDate()} ${MONTHS[d.getMonth()]}`;
    return d.getFullYear() === now.getFullYear() ? short : `${short} ${d.getFullYear()}`;
}

/**
 * Какая клавиша нажата, независимо от раскладки.
 * e.key в русской раскладке даёт «о» вместо «j» и «ы» вместо «s», поэтому буквы и знаки
 * с цифрового ряда определяем по физической клавише (e.code). Служебные клавиши
 * (Enter, Escape, стрелки) от раскладки не зависят — их берём как есть.
 */
export function hotkey(e) {
    const code = e.code || '';
    const letter = /^Key([A-Z])$/.exec(code);
    if (letter) return letter[1].toLowerCase();
    if (code === 'Digit1' && e.shiftKey) return '!';
    if (code === 'Digit3' && e.shiftKey) return '#';
    if (code === 'Digit8' && e.shiftKey) return '*';
    if (code === 'Slash') return e.shiftKey ? '?' : '/';
    if (code === 'Period' && e.shiftKey) return '>';
    return e.key;
}

export function size(bytes) {
    if (!bytes && bytes !== 0) return '';
    if (bytes < 1024) return `${bytes} Б`;
    // Переход к следующей единице делаем по округлённому значению, иначе 1 048 575 Б
    // показывались как «1024 КБ», а гигабайт — как «1024 МБ».
    const kb = Math.round(bytes / 1024);
    if (kb < 1024) return `${kb} КБ`;
    const mb = bytes / 1048576;
    if (mb < 1023.95) return `${mb.toFixed(1).replace('.0', '')} МБ`;
    return `${(mb / 1024).toFixed(1).replace('.0', '')} ГБ`;
}

export function initials(name, mail) {
    const named = !!(name && name !== mail);
    const src = (named ? name : (mail || '')).replace(/["<>]/g, '').trim();
    if (!src) return '·';
    // У адреса без имени берём только часть до «собаки»: домен общий, и вторая буква
    // выходила одинаковой у всех коллег — «ИИ» и у ivanov@innotec.su, и у petrov@innotec.su.
    const base = named ? src : (src.split('@')[0] || src);
    const parts = base.split(/[\s._-]+/).filter(Boolean);
    const s = parts.length >= 2 ? parts[0][0] + parts[1][0] : base.slice(0, 2);
    return s.toUpperCase();
}

/**
 * Оттенок кружка по адресу: у человека он всегда один и тот же, так что письма
 * от одного отправителя видно, не читая имени. Раньше все кружки были одного цвета.
 * Берём остаток от суммы кодов — этого достаточно, чтобы соседние адреса разошлись.
 */
export function hue(mail) {
    const s = String(mail || '').toLowerCase();
    let n = 0;
    for (let i = 0; i < s.length; i++) n = (n * 31 + s.charCodeAt(i)) % 360;
    // Жёлто-зелёный угол на светлом фоне читается плохо (контраст падал до 4.37 при
    // норме 4.5) — сдвигаем мимо него. Полоса 50–120°: узкая 70–110 пропускала 61°.
    return n >= 50 && n < 120 ? n + 80 : n;
}

// Для подписи группы нужен именительный падеж: «Сентябрь», а не «Сентября».
const MONTHS_NAME = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

/**
 * Название группы для разделителя в списке: «Сегодня», «Вчера», «На этой неделе»,
 * «На прошлой неделе», дальше — месяц. Дата в строке остаётся, но искать её глазами
 * по правому краю больше не нужно.
 */
export function dayGroup(iso) {
    if (!iso) return 'Без даты';
    const d = new Date(iso);
    if (isNaN(d)) return 'Без даты';
    const day0 = (x) => { const y = new Date(x); y.setHours(0, 0, 0, 0); return y; };
    const today = day0(new Date());
    const that = day0(d);
    const days = Math.round((today - that) / 86400000);
    if (days === 0) return 'Сегодня';
    if (days === 1) return 'Вчера';
    if (days < 0) return 'Позже';
    // Неделя считается от понедельника, как её считают у нас, а не от воскресенья.
    const monday = new Date(today); monday.setDate(today.getDate() - ((today.getDay() + 6) % 7));
    if (that >= monday) return 'На этой неделе';
    const prev = new Date(monday); prev.setDate(monday.getDate() - 7);
    if (that >= prev) return 'На прошлой неделе';
    const now = new Date();
    const name = MONTHS_NAME[d.getMonth()];
    return d.getFullYear() === now.getFullYear() ? name : name + ' ' + d.getFullYear();
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
    return (list || []).map((a) => (a.name && a.name !== a.mail ? `${quoteName(a.name)} <${a.mail}>` : a.mail)).join(', ');
}
/** Имя с запятой, кавычками или скобками — в кавычки (RFC 5322), иначе «"Фирма" - Иванов» ломает разбор адреса. */
export function quoteName(name) {
    return /[,;<>"()\\]/.test(name) ? `"${name.replace(/["\\]/g, '\\$&')}"` : name;
}
/** Разбить список адресов по запятым, точкам с запятой и переводам строк — но не внутри кавычек и угловых скобок. */
export function splitAddrs(raw) {
    const parts = []; let cur = ''; let quoted = false; let angle = 0; let prev = '';
    for (const ch of raw || '') {
        if (ch === '"' && prev !== '\\') quoted = !quoted;
        else if (!quoted && ch === '<') angle++;
        else if (!quoted && ch === '>') angle = Math.max(0, angle - 1);
        else if ((ch === ',' || ch === ';' || ch === '\n') && !quoted && angle === 0) { parts.push(cur); cur = ''; prev = ch; continue; }
        cur += ch; prev = ch;
    }
    parts.push(cur);
    return parts;
}
/** «Имя <адрес>» или «адрес» → { name, mail }; кавычки и экранирование в имени снимаются. */
export function parseAddr(piece) {
    piece = (piece || '').trim().replace(/[,;]+$/, '').trim();
    if (!piece) return null;
    const m = piece.match(/^([\s\S]*?)\s*<([^<>]+)>$/);
    if (!m) return { name: '', mail: piece.toLowerCase() };
    let name = m[1].trim();
    const q = name.match(/^"([\s\S]*)"$/);
    if (q) name = q[1].replace(/\\(["\\])/g, '$1');
    return { name, mail: m[2].trim().toLowerCase() };
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
