import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    title: (title) => (title ? `${title} — Почта` : 'Почта'),
    // Страницы — отдельными файлами, подгружаются при первом заходе в раздел (раньше вся почта, календарь,
    // контакты, настройки и админка ехали одним файлом на 800 КБ вместе с «Входящими»).
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue');
        const page = pages[`./Pages/${name}.vue`];
        if (! page) throw new Error(`Нет страницы ${name}`);
        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
});
