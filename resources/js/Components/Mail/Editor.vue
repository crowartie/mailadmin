<script setup>
// Простой HTML-редактор на contenteditable: жирный, курсив, списки, ссылка, цитата.
import { onMounted, ref, watch } from 'vue';
import Icon from '../Icon.vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: 'Текст письма…' },
    compact: Boolean,
});
const emit = defineEmits(['update:modelValue', 'submit', 'save', 'toast']);
const el = ref(null);
const state = ref({ bold: false, italic: false, underline: false });

function sync() {
    emit('update:modelValue', el.value.innerHTML);
}

function cmd(name, value = null) {
    el.value.focus();
    document.execCommand(name, false, value);
    sync();
    refresh();
}

function link() {
    let url = window.prompt('Адрес ссылки', 'https://');
    if (!url || url === 'https://') return;
    url = url.trim();
    // «www.site.ru» без схемы браузер считает относительной ссылкой — она ведёт внутрь почты.
    if (!/^[a-z][a-z0-9+.-]*:/i.test(url)) url = 'https://' + url.replace(/^\/+/, '');
    const sel = window.getSelection();
    if (!sel || sel.isCollapsed) {
        // Ничего не выделено: раньше команда просто ничего не делала. Вставляем саму ссылку текстом.
        el.value.focus();
        const safe = url.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
        document.execCommand('insertHTML', false, `<a href="${safe}">${safe}</a>&nbsp;`);
        sync();
        return;
    }
    cmd('createLink', url);
}

function refresh() {
    state.value = {
        bold: document.queryCommandState('bold'),
        italic: document.queryCommandState('italic'),
        underline: document.queryCommandState('underline'),
    };
}

function onKey(e) {
    if (!(e.ctrlKey || e.metaKey)) return;
    // По физической клавише: в русской раскладке e.key даёт «ы» и «л» вместо «s» и «k».
    if (e.key === 'Enter') { e.preventDefault(); emit('submit'); return; }
    if (e.code === 'KeyS') { e.preventDefault(); emit('save'); return; }
    if (e.code === 'KeyK') { e.preventDefault(); link(); }
}

function onPaste(e) {
    // Картинка из буфера (снимок экрана, логотип) — вставляем как картинку.
    const img = [...(e.clipboardData?.files || [])].find((f) => f.type.startsWith('image/'));
    if (img) { e.preventDefault(); insertImage(img); return; }
    // Текст вставляем как текст: чужие стили из Word и сайтов ломают письмо.
    const text = e.clipboardData?.getData('text/plain');
    if (text) {
        e.preventDefault();
        document.execCommand('insertText', false, text);
        sync();
    }
}

// Картинка в тексте (подпись с логотипом и т. п.): встраивается как data: — при отправке сервер превращает её
// во вложение письма (cid), чтобы показывали все почтовые программы. Ограничение — 400 КБ на картинку.
const fileInput = ref(null);
function pickImage() { fileInput.value?.click(); }
function onImageFile(e) { const f = e.target.files?.[0]; e.target.value = ''; if (f) insertImage(f); }
function insertImage(file) {
    if (!file.type.startsWith('image/')) return;
    if (file.size > 400 * 1024) {
        emit('toast', { text: 'Картинка больше 400 КБ — уменьшите её: для подписи хватает ширины 300–400 точек', error: true });
        return;
    }
    const r = new FileReader();
    r.onload = () => { el.value.focus(); document.execCommand('insertHTML', false, `<img src="${r.result}" alt="" style="max-width: 100%; height: auto">`); sync(); };
    r.readAsDataURL(file);
}

onMounted(() => {
    el.value.innerHTML = props.modelValue || '';
});
watch(() => props.modelValue, (v) => {
    if (el.value && el.value.innerHTML !== v) el.value.innerHTML = v || '';
});

defineExpose({
    focus: () => el.value?.focus(),
    focusStart: () => {
        el.value?.focus();
        const sel = window.getSelection(); const range = document.createRange();
        range.setStart(el.value, 0); range.collapse(true); sel.removeAllRanges(); sel.addRange(range);
    },
});
</script>

<template>
    <div class="compose__tools">
        <div class="fmt">
            <button type="button" :class="{ on: state.bold }" title="Жирный (Ctrl+B)" @click="cmd('bold')"><Icon name="bold" :size="15" /></button>
            <button type="button" :class="{ on: state.italic }" title="Курсив (Ctrl+I)" @click="cmd('italic')"><Icon name="italic" :size="15" /></button>
            <button type="button" :class="{ on: state.underline }" title="Подчёркнутый (Ctrl+U)" @click="cmd('underline')"><Icon name="underline" :size="15" /></button>
            <span class="v" />
            <button type="button" title="Ссылка (Ctrl+K)" @click="link"><Icon name="link" :size="15" /></button>
            <button type="button" title="Список" @click="cmd('insertUnorderedList')"><Icon name="ul" :size="15" /></button>
            <button type="button" title="Нумерованный список" @click="cmd('insertOrderedList')"><Icon name="ol" :size="15" /></button>
            <button type="button" title="Цитата" @click="cmd('formatBlock', 'blockquote')"><Icon name="quote" :size="15" /></button>
            <button type="button" title="Картинка (файл или вставка из буфера)" @click="pickImage"><Icon name="img" :size="15" /></button>
            <input ref="fileInput" type="file" accept="image/*" style="display: none" @change="onImageFile">
            <span class="v" />
            <button type="button" title="Убрать форматирование" @click="cmd('removeFormat')"><Icon name="eraser" :size="15" /></button>
        </div>
        <span class="grow" />
        <slot name="right" />
    </div>
    <div
        ref="el"
        class="compose__editor"
        contenteditable="true"
        :data-placeholder="placeholder"
        spellcheck="true"
        @input="sync"
        @keyup="refresh"
        @mouseup="refresh"
        @keydown="onKey"
        @paste="onPaste"
    />
</template>
