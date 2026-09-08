<script setup>
// Карантин сотрудника: письма, которые сервер посчитал спамом и не доставил. «Доставить» кладёт письмо во «Входящие».
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';
import { api } from '../../mail/api';
import { plural, size, when } from '../../mail/format';

const props = defineProps({ user: String, settings: Object, items: Array });
const items = ref(props.items || []);
const busy = ref('');
const toast = ref(null);
function say(text, error = false) { toast.value = { text, error }; setTimeout(() => (toast.value = null), error ? 6000 : 3000); }
async function release(i) {
    busy.value = i.id;
    try { const r = await api.quarantineRelease(i.id); items.value = r.items; say('Письмо доставлено во «Входящие»'); } catch (e) { say(e.message, true); } finally { busy.value = ''; }
}
async function remove(i) {
    busy.value = i.id;
    try { const r = await api.quarantineDelete(i.id); items.value = r.items; } catch (e) { say(e.message, true); } finally { busy.value = ''; }
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
                    <b style="font-size: 16px; margin-left: 8px">Карантин</b>
                    <span class="grow" />
                    <span>{{ items.length }} {{ plural(items.length, 'письмо', 'письма', 'писем') }}</span>
                    <button class="ib ib--sm" type="button" title="Обновить" @click="reload"><Icon name="refresh" :size="14" /></button>
                </div>
                <p class="hint" style="padding: 0 18px 10px; margin: 0">Сюда попадают письма, которые сервер посчитал спамом или опасными и не положил во «Входящие». Если письмо нужное — «Доставить»: оно придёт как обычно, а фильтр запомнит, что от этого отправителя письма нужны. Через 14 дней карантин чистится сам.</p>
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
                    <div v-if="!items.length" class="empty" style="padding-top: 60px">Карантин пуст — ничего подозрительного за последние две недели</div>
                </div>
            </section>
        </div>
        <transition name="flash"><div v-if="toast" class="mtoast" :class="{ 'mtoast--error': toast.error }">{{ toast.text }}</div></transition>
    </MailLayout>
</template>
