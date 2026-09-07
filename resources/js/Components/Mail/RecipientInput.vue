<script setup>
// Поле адресатов: фишки «Имя <адрес>», ввод с подсказками из общей книги и недавних.
import { ref, watch } from 'vue';
import { api } from '../../mail/api';
import { initials } from '../../mail/format';

const props = defineProps({
    modelValue: { type: Array, default: () => [] }, // [{name, mail}]
    placeholder: { type: String, default: '' },
    autofocus: Boolean,
});
const emit = defineEmits(['update:modelValue', 'blur']);

const text = ref('');
const sugg = ref([]);
const active = ref(0);
const input = ref(null);
let timer = null;

const EMAIL = /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/;

function parse(piece) {
    piece = piece.trim().replace(/[,;]+$/, '').trim();
    if (!piece) return null;
    const m = piece.match(/^"?([^"<]*)"?\s*<([^>]+)>$/);
    if (m) return { name: m[1].trim(), mail: m[2].trim().toLowerCase() };
    return { name: '', mail: piece.toLowerCase() };
}

function add(entry) {
    if (!entry) return;
    if (props.modelValue.some((a) => a.mail === entry.mail)) return;
    emit('update:modelValue', [...props.modelValue, { ...entry, bad: !EMAIL.test(entry.mail) }]);
}

function commit() {
    const raw = text.value;
    text.value = '';
    sugg.value = [];
    raw.split(/[,;]+(?![^<]*>)/).forEach((p) => add(parse(p)));
}

function pick(s) {
    text.value = '';
    sugg.value = [];
    add({ name: s.name === s.mail ? '' : s.name, mail: s.mail });
    input.value?.focus();
}

function remove(i) {
    const next = [...props.modelValue];
    next.splice(i, 1);
    emit('update:modelValue', next);
}

function onKey(e) {
    if (e.key === 'ArrowDown' && sugg.value.length) { e.preventDefault(); active.value = (active.value + 1) % sugg.value.length; return; }
    if (e.key === 'ArrowUp' && sugg.value.length) { e.preventDefault(); active.value = (active.value - 1 + sugg.value.length) % sugg.value.length; return; }
    if ((e.key === 'Enter' || e.key === 'Tab' || e.key === ',' || e.key === ';') && (text.value.trim() || sugg.value.length)) {
        if (e.key !== 'Tab' || text.value.trim()) e.preventDefault();
        if (sugg.value.length && (e.key === 'Enter' || e.key === 'Tab')) pick(sugg.value[active.value]);
        else commit();
        return;
    }
    if (e.key === 'Backspace' && !text.value && props.modelValue.length) {
        remove(props.modelValue.length - 1);
    }
    if (e.key === 'Escape') { sugg.value = []; }
}

watch(text, (v) => {
    clearTimeout(timer);
    const q = v.trim();
    if (q.length < 1) { sugg.value = []; return; }
    timer = setTimeout(async () => {
        try {
            const list = await api.suggest(q);
            sugg.value = list.filter((s) => !props.modelValue.some((a) => a.mail === s.mail));
            active.value = 0;
        } catch { sugg.value = []; }
    }, 160);
});

function onBlur() {
    setTimeout(() => {
        if (text.value.trim()) commit();
        sugg.value = [];
        emit('blur');
    }, 150);
}

function onPaste(e) {
    const t = e.clipboardData?.getData('text') || '';
    if (/[,;<]/.test(t) || (t.match(/@/g) || []).length > 1) {
        e.preventDefault();
        t.split(/[,;\n]+(?![^<]*>)/).forEach((p) => add(parse(p)));
    }
}

defineExpose({ focus: () => input.value?.focus() });
</script>

<template>
    <div class="rcpt" @click="input?.focus()">
        <span v-for="(a, i) in modelValue" :key="a.mail + i" class="rcpt__chip" :class="{ 'rcpt__chip--bad': a.bad }" :title="a.mail">
            <span>{{ a.name || a.mail }}</span>
            <button type="button" title="Убрать" @click.stop="remove(i)">✕</button>
        </span>
        <input
            ref="input"
            v-model="text"
            :placeholder="modelValue.length ? '' : placeholder"
            :autofocus="autofocus"
            autocomplete="off"
            spellcheck="false"
            @keydown="onKey"
            @blur="onBlur"
            @paste="onPaste"
        >
        <div v-if="sugg.length" class="rcpt__list">
            <button
                v-for="(s, i) in sugg"
                :key="s.mail"
                type="button"
                class="sug"
                :class="{ 'sug--on': i === active }"
                @mousedown.prevent="pick(s)"
            >
                <span class="sug__av">{{ initials(s.name, s.mail) }}</span>
                <span style="min-width: 0">
                    <div>{{ s.name }}</div>
                    <div class="sug__sub">{{ s.mail }} · {{ s.kind === 'employee' ? 'сотрудник' : 'из переписки' }}</div>
                </span>
            </button>
        </div>
    </div>
</template>
