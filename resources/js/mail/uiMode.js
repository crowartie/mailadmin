/**
 * Вид веб-почты: подробный (как было, по умолчанию) или простой — меньше кнопок на панели
 * письма и в меню правой кнопки, галочки у писем и значки в цепочке только при наведении.
 *
 * Хранится в настройках сотрудника (ui_simple) и приходит в каждую страницу общим свойством
 * uiSimple. Мелкие отличия делают стили по html[data-ui="simple"], крупные (какие кнопки
 * показать) — компоненты по uiSimple.value. Переключается в настройках и в меню по инициалам.
 */
import { ref } from 'vue';

export const uiSimple = ref(false);

function apply(on) {
    uiSimple.value = !!on;
    document.documentElement.dataset.ui = on ? 'simple' : 'full';
}

/** С сервера (общее свойство страницы) — при каждой загрузке и переходе. */
export function initUi(on) {
    apply(on);
}

/** Переключить и запомнить в настройках: работает сразу, без перезагрузки страницы. */
export function setUiSimple(on) {
    apply(on);
    return fetch('/mail/api/settings', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1] || '') },
        body: JSON.stringify({ ui_simple: !!on }),
        credentials: 'same-origin',
    }).catch(() => {});
}
