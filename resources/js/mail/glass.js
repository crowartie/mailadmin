// Схема «Стекло» (обращение №55): оформление «как в демо 4182» — 20 палитр, 6 фоновых рисунков, многослойное
// стекло с кромкой и бликом под указателем, упругие кнопки с волной, скользящая капсула меню папок, двухфазная
// смена страниц. Всё это живёт в браузере того, кто включил схему в «Настройки → Оформление»; сервер хранит только
// пять настроек (glass_*). Данные и список писем остаются непрозрачными — стекло только в обрамлении.
import PALETTES from './glass-palettes.json';
import WALLPAPERS from './glass-wallpapers.json';

export const GLASS_PALETTES = PALETTES;
export const GLASS_WALLPAPERS = WALLPAPERS;
export const GLASS_MOTIONS = { expressive: 'Выразительное', calm: 'Спокойное', off: 'Без анимации' };
export const GLASS_DEFAULTS = { glass_palette: 'porcelain', glass_motion: 'expressive', glass_wallpaper: 'auto', glass_wallpaper_strength: 16, glass_density: 78 };
// Рисунок «авто» — по демо: у шести палитр свой, у остальных без рисунка.
const AUTO_WALLPAPER = { porcelain: 'architecture', terracotta: 'arches', mulberry: 'petals', cocoa: 'linen', atlantic: 'contours', slate: 'orbit' };

const clamp = (v, lo, hi, dflt) => { const n = Number(v); return Number.isFinite(n) ? Math.min(hi, Math.max(lo, Math.round(n))) : dflt; };

/** Настройки схемы с подстановкой стандартных значений и проверкой диапазонов. */
export function glassConfig(s) {
    const o = { ...GLASS_DEFAULTS, ...(s || {}) };
    if (!PALETTES.some((p) => p.id === o.glass_palette)) o.glass_palette = GLASS_DEFAULTS.glass_palette;
    if (!GLASS_MOTIONS[o.glass_motion]) o.glass_motion = GLASS_DEFAULTS.glass_motion;
    if (!(o.glass_wallpaper in WALLPAPERS) && o.glass_wallpaper !== 'auto' && o.glass_wallpaper !== 'none') o.glass_wallpaper = 'auto';
    o.glass_wallpaper_strength = clamp(o.glass_wallpaper_strength, 0, 30, 16);
    o.glass_density = clamp(o.glass_density, 68, 95, 78);
    return o;
}

export function paletteOf(cfg) { return PALETTES.find((p) => p.id === cfg.glass_palette) || PALETTES.find((p) => p.id === 'porcelain'); }

function wallpaperUrl(cfg, blue) {
    const id = cfg.glass_wallpaper === 'auto' ? AUTO_WALLPAPER[cfg.glass_palette] : cfg.glass_wallpaper;
    const w = id && WALLPAPERS[id];
    if (!w) return 'none';
    // Линии рисунка — цветом акцента палитры; SVG вшит строкой, без внешних картинок.
    return `url("data:image/svg+xml,${encodeURIComponent(w.svg.replace(/#41639A/gi, blue))}")`;
}

/**
 * Переменные для :root — токены демо (--fg-*) и наши токены (--bg, --accent…), выведенные из них,
 * чтобы весь интерфейс перекрасился без правки каждого правила.
 */
export function glassCss(cfg) {
    const p = paletteOf(cfg);
    const t = p.tokens;
    const dark = !!p.dark;
    const v = {
        '--fg-base': t.base, '--fg-surface': t.surface, '--fg-overlay': t.overlay, '--fg-blue': t.blue, '--fg-soft': t.soft,
        '--fg-purple': t.purple, '--fg-ink': t.ink, '--fg-muted': t.muted, '--fg-line': t.line, '--fg-haze': t.haze, '--fg-haze2': t.haze2,
        '--fg-rgb': t.rgb, '--fg-glass': (cfg.glass_density / 100).toFixed(2),
        '--fg-side-rgb': t['side-rgb'] || t.rgb, '--fg-side-accent': t['side-accent'] || t.soft, '--fg-side-active': t['side-active'] || t.blue, '--fg-side-muted': t['side-muted'] || t.muted,
        '--glass-wallpaper': wallpaperUrl(cfg, t.blue), '--glass-wp': (cfg.glass_wallpaper_strength / 100).toFixed(2),
        // Наши токены
        '--bg': t.base, '--surface': t.surface, '--surface-2': t.soft, '--border': t.line,
        '--border-2': `color-mix(in srgb, ${t.line} 60%, ${t.muted})`,
        '--text': t.ink, '--muted': t.muted, '--faint': t.muted,
        '--accent': t.blue, '--accent-soft': t.soft, '--accent-ink': dark ? `color-mix(in srgb, ${t.blue} 70%, white)` : `color-mix(in srgb, ${t.blue} 82%, black)`,
        '--accent-on': '#FFFFFF', '--accent-hover': dark ? `color-mix(in srgb, ${t.blue} 85%, white)` : `color-mix(in srgb, ${t.blue} 88%, black)`,
        '--link': t.blue, '--link-hover': dark ? `color-mix(in srgb, ${t.blue} 80%, white)` : `color-mix(in srgb, ${t.blue} 80%, black)`,
        '--chip-off': t.soft, '--overlay': dark ? 'rgb(0 0 0 / .45)' : 'rgb(42 59 89 / .16)',
        '--shadow-pop': dark ? '0 18px 48px rgb(0 0 0 / .5)' : '0 18px 48px rgb(25 43 72 / .2), 0 3px 10px rgb(25 43 72 / .09)',
        'color-scheme': dark ? 'dark' : 'light',
    };
    return Object.entries(v).map(([k, val]) => `${k}:${val}`).join(';');
}

let effects = null;   // запущенные эффекты (слушатели, наблюдатели) — чтобы снять при выключении схемы

export function applyGlass(settings) {
    const cfg = glassConfig(settings);
    const p = paletteOf(cfg);
    const root = document.documentElement;
    root.dataset.scheme = 'glass';
    root.dataset.theme = p.dark ? 'dark' : 'light';
    root.dataset.motion = reducedMotion() ? 'off' : cfg.glass_motion;
    if (p.split) root.dataset.glassSplit = '1'; else delete root.dataset.glassSplit;
    const css = glassCss(cfg);
    root.setAttribute('style', css);
    try {
        localStorage.setItem('mail.scheme', 'glass');
        localStorage.setItem('mail.glassStyle', css);
        localStorage.setItem('mail.glassDark', p.dark ? '1' : '0');
        localStorage.setItem('mail.glassMotion', root.dataset.motion);
        localStorage.setItem('mail.glassSplit', p.split ? '1' : '0');
        localStorage.setItem('mail.glassCfg', JSON.stringify(cfg));
    } catch { /* приватный режим */ }
    startEffects(cfg);
    return cfg;
}

export function removeGlass() {
    const root = document.documentElement;
    root.removeAttribute('style');
    delete root.dataset.motion; delete root.dataset.glassSplit;
    try { ['mail.glassStyle', 'mail.glassDark', 'mail.glassMotion', 'mail.glassSplit', 'mail.glassCfg'].forEach((k) => localStorage.removeItem(k)); } catch { /* приватный режим */ }
    stopEffects();
}

/** Настройки, с которыми схема включалась в прошлый раз (страницы, которым нечего передать в MailLayout). */
export function cachedGlass() {
    try { return JSON.parse(localStorage.getItem('mail.glassCfg') || 'null'); } catch { return null; }
}

function reducedMotion() { return typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches; }
const EASE_OUT = 'cubic-bezier(.2,.7,.2,1)';

// ── Эффекты ────────────────────────────────────────────────────────────────

const SHINE = '.card, .mset__section, .dialog, .pop, .mread, .mnav, .mlist, .sheet__panel';
const PRESS = '.btn, .ib, .seg__item, .fab, .tabbar__item, .rail__item';

function startEffects(cfg) {
    stopEffects();
    const motion = document.documentElement.dataset.motion;
    const fx = { raf: 0, last: null, handlers: [], observers: [], capsule: null, capsuleAnim: null, timers: [] };
    effects = fx;
    const on = (target, ev, fn, opts) => { target.addEventListener(ev, fn, opts); fx.handlers.push(() => target.removeEventListener(ev, fn, opts)); };

    // Блик и кромка под указателем: одна отрисовка на кадр, координаты в CSS-переменных поверхности.
    // На сенсорных экранах не следим (нет наведения).
    const shine = motion === 'expressive' ? 1 : motion === 'calm' ? .55 : 0;
    let lit = null;
    on(document, 'pointermove', (e) => {
        if (e.pointerType === 'touch' || shine === 0) return;
        fx.last = e;
        if (fx.raf) return;
        fx.raf = requestAnimationFrame(() => {
            fx.raf = 0;
            const ev = fx.last; if (!ev) return;
            const el = ev.target instanceof Element ? ev.target.closest(SHINE) : null;
            if (lit && lit !== el) { lit.style.removeProperty('--glass-shine'); lit = null; }
            if (!el) return;
            const r = el.getBoundingClientRect();
            el.style.setProperty('--glass-x', `${Math.round(ev.clientX - r.left)}px`);
            el.style.setProperty('--glass-y', `${Math.round(ev.clientY - r.top)}px`);
            el.style.setProperty('--glass-shine', String(shine));
            lit = el;
        });
    }, { passive: true });
    on(document, 'pointerout', (e) => { if (!e.relatedTarget && lit) { lit.style.removeProperty('--glass-shine'); lit = null; } }, { passive: true });

    // Упругое нажатие и круговая волна (только expressive, на главных кнопках).
    if (motion !== 'off') {
        on(document, 'pointerdown', (e) => {
            if (e.button !== 0 || !(e.target instanceof Element)) return;
            const b = e.target.closest(PRESS);
            if (!b) return;
            b.getAnimations?.().forEach((a) => { if (a.id === 'glass-press') a.cancel(); });
            const a = motion === 'expressive'
                ? b.animate([{ transform: 'scale(1)' }, { transform: 'scale(.96)' }, { transform: 'scale(1)' }], { duration: 350, easing: 'cubic-bezier(.2,.8,.2,1)' })
                : b.animate([{ transform: 'scale(1)' }, { transform: 'scale(.985)' }, { transform: 'scale(1)' }], { duration: 200, easing: 'cubic-bezier(.2,.8,.2,1)' });
            a.id = 'glass-press';
            if (motion === 'expressive' && b.matches('.btn--primary, .fab')) ripple(b, e);
        }, { passive: true });
    }

    // Скользящая капсула под активной папкой.
    if (motion !== 'off') startCapsule(fx, motion);

    // Двухфазная смена страницы: уходящая — вверх и прозрачно, входящая — снизу с задержкой.
    if (motion !== 'off') startPageTransition(fx, motion);

    // Смена системного «меньше движения» на лету.
    if (typeof matchMedia === 'function') {
        const mq = matchMedia('(prefers-reduced-motion: reduce)');
        on(mq, 'change', () => { const c = cachedGlass(); if (c) applyGlass(c); });
    }
}

function stopEffects() {
    const fx = effects; if (!fx) return;
    effects = null;
    if (fx.raf) cancelAnimationFrame(fx.raf);
    fx.handlers.forEach((off) => off());
    fx.observers.forEach((o) => o.disconnect());
    fx.timers.forEach((t) => clearTimeout(t));
    fx.capsuleAnim?.cancel();
    fx.capsule?.remove();
    document.querySelectorAll('.glass-ripple').forEach((r) => r.remove());
}

function ripple(btn, e) {
    const r = btn.getBoundingClientRect();
    const dot = document.createElement('span');
    dot.className = 'glass-ripple';
    dot.setAttribute('aria-hidden', 'true');
    dot.style.left = `${e.clientX - r.left - 12}px`;
    dot.style.top = `${e.clientY - r.top - 12}px`;
    btn.appendChild(dot);
    const a = dot.animate([{ transform: 'scale(.2)', opacity: .8 }, { transform: 'scale(4)', opacity: 0 }], { duration: 550, easing: 'cubic-bezier(.2,.7,.3,1)' });
    a.onfinish = a.oncancel = () => dot.remove();
}

/** Капсула меню папок: один элемент в .mnav__scroll, едет к активному пункту; при быстрых кликах стартует с текущего места. */
function startCapsule(fx, motion) {
    const place = (animate) => {
        const nav = document.querySelector('.mnav__scroll');
        if (!nav) { fx.capsule?.remove(); fx.capsule = null; return; }
        const active = nav.querySelector('.mnav__item--on');
        if (!fx.capsule || !nav.contains(fx.capsule)) {
            fx.capsule?.remove();
            fx.capsule = document.createElement('div');
            fx.capsule.className = 'mnav__glass';
            fx.capsule.setAttribute('aria-hidden', 'true');
            nav.prepend(fx.capsule);
        }
        const cap = fx.capsule;
        if (!active) { cap.style.opacity = '0'; return; }
        const nr = nav.getBoundingClientRect(); const ar = active.getBoundingClientRect();
        const to = { x: ar.left - nr.left - nav.clientLeft, y: ar.top - nr.top - nav.clientTop + nav.scrollTop, w: ar.width, h: ar.height };
        const from = cap._pos;
        cap._pos = to;
        cap.style.opacity = '1';
        const setTo = () => { cap.style.width = `${to.w}px`; cap.style.height = `${to.h}px`; cap.style.transform = `translate(${to.x}px, ${to.y}px)`; };
        if (!animate || !from) { fx.capsuleAnim?.cancel(); setTo(); return; }
        // Текущее положение берём из прямоугольника капсулы до отмены старой анимации.
        const cr = cap.getBoundingClientRect();
        const cur = { x: cr.left - nr.left - nav.clientLeft, y: cr.top - nr.top - nav.clientTop + nav.scrollTop, w: cr.width, h: cr.height };
        fx.capsuleAnim?.cancel();
        setTo();
        const kf = [{ transform: `translate(${cur.x}px, ${cur.y}px)`, width: `${cur.w}px`, height: `${cur.h}px` }];
        if (motion === 'expressive') kf.push({ transform: `translate(${to.x}px, ${to.y}px) scale(1.035, .94)`, width: `${to.w}px`, height: `${to.h}px`, offset: .72 });
        kf.push({ transform: `translate(${to.x}px, ${to.y}px)`, width: `${to.w}px`, height: `${to.h}px` });
        fx.capsuleAnim = cap.animate(kf, motion === 'expressive' ? { duration: 560, easing: 'cubic-bezier(.2,.85,.25,1.1)' } : { duration: 250, easing: EASE_OUT });
    };
    const schedule = (animate) => { fx.timers.push(setTimeout(() => place(animate), 0)); };
    const mo = new MutationObserver((muts) => { if (muts.some((m) => m.type === 'childList' || m.attributeName === 'class')) schedule(true); });
    mo.observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
    fx.observers.push(mo);
    if (typeof ResizeObserver === 'function') {
        const ro = new ResizeObserver(() => schedule(false));
        const nav = document.querySelector('.mnav__scroll'); if (nav) ro.observe(nav);
        fx.observers.push(ro);
    }
    document.fonts?.ready?.then(() => schedule(false));
    schedule(false);
}

/** Уход старой страницы (180 мс) и вход новой (420 мс с задержкой 90) — только при смене адреса, не при подгрузке данных. */
function startPageTransition(fx, motion) {
    let router = null;
    import('@inertiajs/vue3').then((m) => { router = m.router; hook(); }).catch(() => {});
    const content = () => [...document.querySelectorAll('.app > *')].find((el) => !el.matches('.rail, .tabbar, .sheet, .toast, .overlay'));
    function hook() {
        if (!effects || effects !== fx) return;
        let leaving = null;
        const offStart = router.on('start', (e) => {
            const url = e.detail?.visit?.url; if (!url || (e.detail?.visit?.only || []).length) return;
            if (url.pathname === window.location.pathname) return;
            const el = content(); if (!el) return;
            leaving = el.animate(
                motion === 'expressive' ? [{ opacity: 1, transform: 'translateY(0)' }, { opacity: 0, transform: 'translateY(-7px)' }] : [{ opacity: 1 }, { opacity: 0 }],
                { duration: motion === 'expressive' ? 180 : 100, easing: 'ease-out', fill: 'forwards' },
            );
        });
        const offNav = router.on('navigate', () => {
            leaving?.cancel(); leaving = null;
            fx.timers.push(setTimeout(() => {
                const el = content(); if (!el) return;
                el.getAnimations?.().forEach((a) => a.cancel());
                el.animate(
                    motion === 'expressive' ? [{ opacity: 0, transform: 'translateY(18px)' }, { opacity: 1, transform: 'translateY(0)' }] : [{ opacity: 0, transform: 'translateY(7px)' }, { opacity: 1, transform: 'translateY(0)' }],
                    { duration: motion === 'expressive' ? 420 : 220, delay: motion === 'expressive' ? 90 : 40, easing: EASE_OUT, fill: 'backwards' },
                );
            }, 0));
        });
        fx.handlers.push(offStart, offNav);
    }
}
