// Вопрос «вы уверены?» в стиле почты вместо окна браузера «Подтвердите действие на сайте…».
//
//   if (!(await ask('Удалить письмо?', { ok: 'Удалить', danger: true }))) return;
//
// Окно рисует ConfirmHost (он стоит в обоих каркасах: почта и админка). Если его на странице
// нет — отвечает обычный confirm браузера, чтобы действие не потеряло вопрос вовсе.
import { reactive } from 'vue';

export const confirmState = reactive({ open: false, title: '', text: '', ok: 'Да', cancel: 'Отмена', danger: false, hosts: 0, resolve: null });

/** @returns {Promise<boolean>} true — человек согласился */
export function ask(text, { title = '', ok = 'Да', cancel = 'Отмена', danger = false } = {}) {
    if (!confirmState.hosts) return Promise.resolve(window.confirm(text));
    // Новый вопрос поверх старого: старый считаем отменённым.
    if (confirmState.resolve) confirmState.resolve(false);

    return new Promise((resolve) => {
        Object.assign(confirmState, { open: true, title, text, ok, cancel, danger, resolve });
    });
}

export function answer(yes) {
    const r = confirmState.resolve;
    confirmState.open = false;
    confirmState.resolve = null;
    if (r) r(!!yes);
}
