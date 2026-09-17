<script setup>
// Карантин сотрудника: письма, которые сервер посчитал спамом и не доставил. «Доставить» кладёт письмо во «Входящие».
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import { api } from '../../mail/api';
import { plural, size, when } from '../../mail/format';

const props = defineProps({ user: String, settings: Object, items: Array, keepDays: { type: Number, default: 14 } });
const items = ref(props.items || []);
const busy = ref('');
const ask = ref(null); // { from, domain, busy }
const toast = ref(null);
function say(text, error = false) { toast.value = { text, error }; setTimeout(() => (toast.value = null), error ? 6000 : 3000); }
async function release(i) {
    busy.value = i.id;
    try {
        const r = await api.quarantineRelease(i.id); items.value = r.items; say('Письмо доставлено во «Входящие»');
        const from = (i.from || '').toLowerCase().match(/[^\s<>"']+@[^\s<>"']+/)?.[0];
        if (from) ask.value = { from, domain: from.split('@')[1], busy: false };
    } catch (e) { say(e.message, true); } finally { busy.value = ''; }
}
async function notSpam(match) {
    if (!ask.value || ask.value.busy) return;
    ask.value.busy = true;
    try {
        const r = await api.markSender('ham', match, match === 'domain' ? ask.value.domain : ask.value.from, true);
        say(`${r.global ? 'Добавлено в исключения' : 'Заявка на исключение ушла администратору'}: ${match === 'domain' ? '@' + ask.value.domain : ask.value.from}${r.moved ? `, из «Спама» возвращено писем: ${r.moved}` : ''}`);
        ask.value = null;
    } catch (e) { ask.value.busy = false; say(e.message, true); }
}
async function remove(i) {
    // 283: кнопка стоит вплотную к «Доставить», а действие необратимо — и ни вопроса,
    // ни сообщения об успехе не было.
    if (!window.confirm(`Удалить письмо «${i.subject || 'без темы'}» из карантина навсегда? Восстановить его будет нельзя.`)) return;
    busy.value = i.id;
    try {
        const r = await api.quarantineDelete(i.id);
        items.value = r.items;
        say('Письмо удалено из карантина');
    } catch (e) { say(e.message, true); } finally { busy.value = ''; }
}
async function reload() { try { items.value = await api.quarantineList(); } catch (e) { say(e.message, true); } }
</script>

<template>
    <Head title="Карантин" />
    <MailLayout :user="user" :theme="settings.theme">
        <div class="mail" style="display: block">
            <section class="mlist" style="max-width: 1100px; margin: 0 auto; width: 100%; border: 0">
                <div class="mlist__meta" style="padding: 14px 18px 6px">
                    <a href="/mail" class="ib ib--sm"><Icon name="back" :size="16" />Почта</a>
                    <!-- 279: заголовок страницы был бледнее соседней ссылки «Почта» — порядок
                         важности на экране получался обратный. -->
                    <h1 style="font-size: 18px; font-weight: 700; margin: 0 0 0 8px; color: var(--text)">Карантин</h1>
                    <span class="grow" />
                    <span>{{ items.length }} {{ plural(items.length, 'письмо', 'письма', 'писем') }}</span>
                    <button class="ib ib--sm" type="button" title="Обновить" @click="reload" aria-label="Обновить"><Icon name="refresh" :size="14" /></button>
                </div>
                <!-- 280: текст шёл во всю ширину экрана мелким бледным шрифтом. -->
                <p class="hint" style="padding: 0 18px 10px; margin: 0; max-width: 70ch; font-size: 13px; color: var(--muted)">Сюда попадают письма, которые сервер посчитал спамом или опасными и не положил во «Входящие». Если письмо нужное — «Доставить»: оно придёт как обычно, а вы сможете добавить отправителя в исключения, чтобы фильтр больше его не трогал. Через {{ keepDays }} {{ plural(keepDays, 'день', 'дня', 'дней') }} карантин чистится сам.</p>
                <div v-if="ask" class="attn" style="margin: 0 18px 10px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
                    <Icon name="check" :size="16" /><span>Это не спам? Больше не задерживать письма</span>
                    <button class="btn btn--sm btn--primary" type="button" :disabled="ask.busy" @click="notSpam('address')">с адреса {{ ask.from }}</button>
                    <button class="btn btn--sm" type="button" :disabled="ask.busy" @click="notSpam('domain')">со всего домена @{{ ask.domain }}</button>
                    <button class="btn btn--sm" type="button" @click="ask = null">Не нужно</button>
                </div>
                <div class="mlist__rows">
                    <div v-for="i in items" :key="i.id" class="mrow" style="cursor: default; align-items: center">
                        <span class="mrow__av" :style="{ background: i.kind === 'вирус' ? 'var(--no)' : 'var(--warn)', color: '#fff' }"><Icon :name="i.kind === 'вирус' ? 'warn' : 'spam'" :size="16" /></span>
                        <span class="mrow__body">
                            <span class="mrow__from"><b>{{ i.from }}</b></span>
                            <span class="mrow__prev">{{ i.subject }} · {{ i.kind }}{{ i.score != null ? ' ' + i.score : '' }} · {{ size(i.size) }}</span>
                        </span>
                        <span class="mrow__when" style="display: flex; gap: 6px; align-items: center">
                            <span class="faint" style="margin-right: 6px">{{ when(i.time) }}</span>
                            <button class="btn btn--sm btn--primary" type="button" :disabled="busy === i.id" @click="release(i)">Доставить</button>
                            <button class="btn btn--sm" type="button" :disabled="busy === i.id" @click="remove(i)">Удалить</button>
                        </span>
                    </div>
                    <!-- 281, 282: пустая страница выглядела незавершённой, а вернуться
                         можно было только ссылкой «Почта» вверху. -->
                    <div v-if="!items.length" class="empty" style="padding: 60px 18px 40px">
                        Карантин пуст — ничего подозрительного за последние {{ keepDays }} {{ plural(keepDays, 'день', 'дня', 'дней') }}.
                        <div style="margin-top: 14px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap">
                            <a class="btn btn--sm btn--primary" href="/mail">Во «Входящие»</a>
                            <a class="btn btn--sm" href="/mail/folder/Junk">Открыть «Спам»</a>
                            <a class="btn btn--sm" href="/mail/help#spam">Как это работает</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <transition name="flash"><div v-if="toast" class="mtoast" :class="{ 'mtoast--error': toast.error }">{{ toast.text }}</div></transition>
    </MailLayout>
</template>
