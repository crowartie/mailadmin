<script setup>
// Карантин сотрудника: письма, которые сервер посчитал спамом и не доставил. «Доставить» кладёт письмо во «Входящие».
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import { api } from '../../mail/api';
import { plural, size, when } from '../../mail/format';
import { ask as confirmAsk } from '../../confirm';

const props = defineProps({ user: String, settings: Object, items: Array, keepDays: { type: Number, default: 14 }, spamPath: { type: String, default: 'Junk' } });
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
        say(`${r.global ? 'Добавлено в исключения' : 'Заявка на исключение ушла администратору'}: ${match === 'domain' ? '@' + ask.value.domain : ask.value.from}${r.moved ? `, из «Спама» возвращено писем: ${r.moved}` : ''}${r.resorting ? ', письма из «Спама» возвращаются во «Входящие»' : ''}`);
        ask.value = null;
    } catch (e) { ask.value.busy = false; say(e.message, true); }
}
async function remove(i) {
    // 283: кнопка стоит вплотную к «Доставить», а действие необратимо — и ни вопроса,
    // ни сообщения об успехе не было.
    if (!(await confirmAsk(`Удалить письмо «${i.subject || 'без темы'}» из карантина навсегда? Восстановить его будет нельзя.`, { ok: 'Удалить', danger: true }))) return;
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
    <MailLayout :user="user" :theme="settings.theme" :scheme="settings.scheme">
        <!-- Шапка и карточка — как у «Настроек» и «Справки»: раньше страница прилипала к верху,
             а описание стояло левее заголовка. -->
        <div class="mset">
            <div class="page-head" style="margin-bottom: 8px; max-width: 1100px">
                <Link href="/mail" class="ib" title="К письмам" aria-label="К письмам"><Icon name="back" :size="18" /></Link>
                <h1>Карантин</h1>
                <span class="page-head__count">{{ items.length }} {{ plural(items.length, 'письмо', 'письма', 'писем') }}</span>
                <span style="flex: 1" />
                <button class="ib" type="button" title="Обновить" @click="reload" aria-label="Обновить"><Icon name="refresh" :size="16" /></button>
            </div>
            <!-- 280: текст шёл во всю ширину экрана мелким бледным шрифтом. -->
            <p class="hint qhint">Сюда попадают письма, которые сервер посчитал спамом или опасными и не положил во «Входящие». Если письмо нужное — «Доставить»: оно придёт как обычно, а вы сможете добавить отправителя в исключения, чтобы фильтр больше его не трогал. Через {{ keepDays }} {{ plural(keepDays, 'день', 'дня', 'дней') }} карантин чистится сам.</p>
            <section class="card mlist" style="width: auto; max-width: 1100px; flex: none; border-right: 1px solid var(--border); overflow: hidden">
                <div v-if="ask" class="attn" style="margin: 12px 18px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap">
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
                            <a class="btn btn--sm" :href="'/mail/folder/' + encodeURIComponent(spamPath)">Открыть «Спам»</a>
                            <a class="btn btn--sm" href="/mail/help#spam">Как это работает</a>
                        </div>
                    </div>
                </div>
            </section>
        </div>
        <transition name="flash"><div v-if="toast" class="mtoast" :class="{ 'mtoast--error': toast.error }">{{ toast.text }}</div></transition>
    </MailLayout>
</template>
