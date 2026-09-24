<script setup>
// Письма в работе: строка вкладок внизу окна письма (как в Outlook). Сюда попадают письма,
// которые пишутся, и прочитанные, закреплённые кнопкой «Держать под рукой» (серые, «только
// чтение»). Просто просмотренные письма — это выделение в списке, их тут нет.
// На телефоне вместо строки — кнопка «N письма в работе» со списком.
import { computed, ref } from 'vue';
import Icon from '../Icon.vue';
import { plural } from '../../mail/format';

const props = defineProps({
    tabs: { type: Array, default: () => [] },
    active: { type: Number, default: null },     // token открытого сейчас письма
    max: { type: Number, default: 5 },
    variant: { type: String, default: 'bar' },   // bar — строка (компьютер), pill — кнопка (телефон)
});
const emit = defineEmits(['open', 'close', 'new']);

const TITLES = { reply: 'Ответ', replyAll: 'Ответ всем', forward: 'Пересылка', forwardAttach: 'Пересылка', again: 'Новое письмо', new: 'Новое письмо' };
const ICONS = { reply: 'reply', replyAll: 'reply', forward: 'fwd', forwardAttach: 'fwd' };

const rows = computed(() => props.tabs.map((t) => {
    if (t.kind === 'read') {
        return {
            token: t.token, read: true, title: (t.subject || '').trim() || 'Без темы',
            sub: [t.from, 'только чтение'].filter(Boolean).join(' · '),
            icon: 'mail', on: t.token === props.active, dirty: false, error: false,
        };
    }
    const m = t.meta || {};
    const mode = m.mode || t.mode;
    const on = t.token === props.active;
    const error = typeof m.saved === 'string' && m.saved.startsWith('Черновик не сохранён');
    const when = (m.saved || '').replace(/^Черновик сохранён\s*/, 'сохранён ');
    return {
        token: t.token,
        title: (m.subject ?? t.subject ?? '').trim() || (TITLES[mode] && mode !== 'new' && mode !== 'draft' ? TITLES[mode] : 'Без темы'),
        sub: [m.to || '', on ? 'пишете сейчас' : (t.error ? 'не отправлено: ' + t.error : (error ? 'черновик не сохранён' : when))].filter(Boolean).join(' · '),
        icon: t.error ? 'warn' : (ICONS[mode] || 'edit'),
        on,
        dirty: !!m.dirty && !on,
        error: !!t.error || error,
    };
}));

const open = ref(false);   // список на телефоне
function pick(token) { open.value = false; emit('open', token); }
</script>

<template>
    <div v-if="variant === 'bar'" class="ctabs" role="tablist" aria-label="Письма в работе">
        <span class="ctabs__cap">В работе</span>
        <div v-for="r in rows" :key="r.token" class="ctab" :class="{ 'ctab--on': r.on, 'ctab--err': r.error, 'ctab--read': r.read }" role="presentation">
            <button class="ctab__main" type="button" role="tab" :aria-selected="r.on" :title="r.title + (r.sub ? ' — ' + r.sub : '')" @click="$emit('open', r.token)">
                <Icon :name="r.icon" :size="15" />
                <span class="ctab__txt"><b>{{ r.title }}</b><span>{{ r.sub }}</span></span>
            </button>
            <span v-if="r.dirty" class="ctab__dot" title="Последние правки ещё сохраняются" />
            <button class="ctab__x" type="button" :title="r.read ? 'Убрать вкладку — письмо останется на месте' : 'Закрыть — письмо останется в «Черновиках»'" :aria-label="'Закрыть «' + r.title + '»' + (r.read ? '' : ' — письмо останется в черновиках')" @click="$emit('close', r.token)"><Icon name="x" :size="13" /></button>
        </div>
        <button v-if="tabs.length < max" class="ctabs__new" type="button" title="Новое письмо (c)" aria-label="Новое письмо" @click="$emit('new')"><Icon name="plus" :size="15" /></button>
        <span class="grow" />
        <span class="ctabs__count">{{ tabs.length }} из {{ max }} · Alt+1…{{ max }}</span>
    </div>

    <div v-else class="ctabs-pill">
        <div v-if="open" class="ctabs-pill__list" role="dialog" aria-label="Письма в работе">
            <div class="ctabs-pill__head"><b>Письма в работе</b><span>{{ tabs.length }} из {{ max }}</span></div>
            <div v-for="r in rows" :key="r.token" class="ctab ctab--wide" :class="{ 'ctab--err': r.error, 'ctab--read': r.read }">
                <button class="ctab__main" type="button" @click="pick(r.token)">
                    <Icon :name="r.icon" :size="15" />
                    <span class="ctab__txt"><b>{{ r.title }}</b><span>{{ r.sub }}</span></span>
                </button>
                <span v-if="r.dirty" class="ctab__dot" />
                <button class="ctab__x" type="button" :aria-label="'Закрыть «' + r.title + '»'" @click="$emit('close', r.token)"><Icon name="x" :size="13" /></button>
            </div>
        </div>
        <button class="ctabs-pill__btn" type="button" :aria-expanded="open" @click="open = !open">
            <Icon name="edit" :size="16" />{{ tabs.length }} {{ plural(tabs.length, 'письмо', 'письма', 'писем') }} в работе
        </button>
    </div>
</template>
