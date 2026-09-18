import { computed, ref } from 'vue';

/**
 * Ширина колонок «папки» и «список»: тянется за разделитель и запоминается в браузере.
 *
 * Ниже 360 точек у списка обрезаются вкладки отбора, выше 820 он перестаёт быть списком —
 * поэтому пределы жёсткие. Запись в хранилище всегда в try: в приватном окне она бросает,
 * и раньше это роняло всю страницу почты.
 */
const LIMITS = { nav: [160, 420], list: [360, 820] };
const KEY = 'mail.cols';

function load() {
    try {
        return JSON.parse(localStorage.getItem(KEY) || '{}');
    } catch {
        return {};
    }
}

function save(value) {
    try {
        localStorage.setItem(KEY, JSON.stringify(value));
    } catch {
        /* приватный режим */
    }
}

export function useColumns() {
    const width = ref(load());
    const resizing = ref(false);

    const style = computed(() => ({
        '--nav-w': width.value.nav ? width.value.nav + 'px' : undefined,
        '--list-w': width.value.list ? width.value.list + 'px' : undefined,
    }));

    function startResize(which, e) {
        if (e.button !== 0) return;
        e.preventDefault();
        const pane = e.currentTarget.previousElementSibling;
        const startX = e.clientX;
        const startW = pane.getBoundingClientRect().width;
        const [min, max] = LIMITS[which];
        resizing.value = true;
        const move = (ev) => {
            width.value = { ...width.value, [which]: Math.round(Math.min(max, Math.max(min, startW + ev.clientX - startX))) };
        };
        const up = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            resizing.value = false;
            save(width.value);
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', up);
    }

    /** Двойной щелчок по разделителю возвращает колонке ширину по умолчанию. */
    function resetColumn(which) {
        const next = { ...width.value };
        delete next[which];
        width.value = next;
        save(next);
    }

    return { colStyle: style, resizing, startResize, resetCol: resetColumn };
}
