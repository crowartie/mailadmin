<script setup>
// Поле адресатов: фишки «Имя <адрес>», ввод с подсказками из общей книги и недавних.
import { ref, watch } from 'vue';
import { api } from '../../mail/api';
import { initials, parseAddr, splitAddrs } from '../../mail/format';

const props = defineProps({
    modelValue: { type: Array, default: () => [] }, // [{name, mail}]
    // Адреса из соседних полей: дубль между «Кому» и «Копией» раньше не замечался,
    // и человек получал два экземпляра письма.
    others: { type: Array, default: () => [] },
    // id самого поля ввода: <label for> должен указывать на него, а не на обёртку,
    // иначе клик по подписи не ставит курсор и экранный диктор поле не называет.
    inputId: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    autofocus: Boolean,
});
const emit = defineEmits(['update:modelValue', 'blur', 'note']);

// Последнее известное значение списка: props.modelValue обновится лишь на следующем тике, а add()/verify()
// бывают по несколько подряд — иначе вставка «a, b, c» оставляла одну фишку.
let latest = props.modelValue;
watch(() => props.modelValue, (v) => { latest = v; });
function update(next) {
    latest = next;
    emit('update:modelValue', next);
}

const text = ref('');
const sugg = ref([]);
const active = ref(0);
const input = ref(null);
const listEl = ref(null);
// 133: Backspace на пустом вводе сразу стирал последнюю фишку. Теперь первый Backspace
// её выделяет, второй — убирает; это стандартное поведение полей с фишками.
const armed = ref(false);
let timer = null;
// 137: устаревший ответ подсказок затирал свежий — считаем запросы.
let suggestSeq = 0;

const EMAIL = /^[^\s@<>]+@[^\s@<>]+\.[^\s@<>]+$/;

// Проверка домена получателя (существует ли, принимает ли почту): результат на домен запоминаем на время сессии.
const domainCache = new Map();
async function checkDomain(domain) {
    if (!domainCache.has(domain)) {
        domainCache.set(domain, api.checkDomain(domain).catch(() => ({ status: 'ok' })));
    }
    return domainCache.get(domain);
}
async function verify(entry) {
    const domain = (entry.mail.split('@')[1] || '').toLowerCase();
    if (!domain) return;
    const r = await checkDomain(domain);
    const patch = r.status === 'ok' ? { checked: true } : { checked: true, warn: r.text, suggestion: r.suggestion };
    update(latest.map((a) => (a.mail === entry.mail ? { ...a, ...patch } : a)));
}
watch(() => props.modelValue, (list) => {
    list.filter((a) => !a.bad && !a.checked && !a.checking).forEach((a) => { a.checking = true; verify(a); });
}, { immediate: true });

function fix(i) {
    const a = latest[i];
    const next = [...latest];
    next[i] = { name: a.name, mail: a.mail.replace(/@.*$/, '@' + a.suggestion) };
    update(next);
}

function parse(piece) { return parseAddr(piece); }

function add(entry) {
    if (!entry) return;
    // 135: повтор отбрасывался молча, и человек думал, что вставка не сработала.
    if (latest.some((a) => a.mail === entry.mail)) {
        emit('note', `${entry.mail} уже в этом поле`);
        return;
    }
    // 134: тот же адрес в соседнем поле — письмо ушло бы человеку дважды.
    if (props.others.some((m) => String(m).toLowerCase() === entry.mail.toLowerCase())) {
        emit('note', `${entry.mail} уже указан в другом поле — второй раз письмо не нужно`);
        return;
    }
    update([...latest, { ...entry, bad: !EMAIL.test(entry.mail) }]);
}

function commit() {
    const raw = text.value;
    text.value = '';
    sugg.value = [];
    splitAddrs(raw).forEach((p) => add(parse(p)));
}

function pick(s) {
    text.value = '';
    sugg.value = [];
    add({ name: s.name === s.mail ? '' : s.name, mail: s.mail });
    input.value?.focus();
}

function remove(i) {
    const next = [...latest];
    next.splice(i, 1);
    update(next);
}

function onKey(e) {
    // 138: стрелками можно было уйти за пределы видимого списка — подводим выбранное к виду.
    if (e.key === 'ArrowDown' && sugg.value.length) { e.preventDefault(); active.value = (active.value + 1) % sugg.value.length; reveal(); return; }
    if (e.key === 'ArrowUp' && sugg.value.length) { e.preventDefault(); active.value = (active.value - 1 + sugg.value.length) % sugg.value.length; reveal(); return; }
    if ((e.key === 'Enter' || e.key === 'Tab' || e.key === ',' || e.key === ';') && (text.value.trim() || sugg.value.length)) {
        if (e.key !== 'Tab' || text.value.trim()) e.preventDefault();
        // Набран готовый адрес — берём именно его: раньше Enter подставлял подсвеченную
        // подсказку, и письмо уходило другому человеку с похожим адресом.
        if (sugg.value.length && (e.key === 'Enter' || e.key === 'Tab') && !EMAIL.test(text.value.trim())) pick(sugg.value[active.value]);
        else commit();
        return;
    }
    if (e.key === 'Backspace' && !text.value && latest.length) {
        if (armed.value) {
            armed.value = false;
            remove(latest.length - 1);
        } else {
            armed.value = true;
        }

        return;
    }
    if (e.key !== 'Backspace') armed.value = false;
    // Останавливаем событие, только если было что закрывать: иначе Escape доходил до окна письма
    // и закрывал его целиком вместе со списком подсказок.
    if (e.key === 'Escape' && sugg.value.length) { sugg.value = []; e.stopPropagation(); }
}

function reveal() {
    const box = listEl.value;
    const el = box?.children?.[active.value];
    if (el?.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
}

watch(text, (v) => {
    clearTimeout(timer);
    armed.value = false;
    const q = v.trim();
    if (q.length < 1) { sugg.value = []; return; }
    timer = setTimeout(async () => {
        const want = ++suggestSeq;
        try {
            const list = await api.suggest(q);
            // Ответ на прежний, более короткий запрос приходил позже и затирал свежий список.
            if (want !== suggestSeq) return;
            const busy = [...latest.map((a) => a.mail), ...props.others].map((m) => String(m).toLowerCase());
            sugg.value = list.filter((s) => !busy.includes(String(s.mail).toLowerCase()));
            active.value = 0;
        } catch { if (want === suggestSeq) sugg.value = []; }
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
        splitAddrs(t).forEach((p) => add(parse(p)));
    }
}

/** Превратить набранный текст в фишку прямо сейчас: окно письма зовёт это перед отправкой,
 *  иначе клик по «Отправить» сразу после набора адреса терял последнего получателя. */
function flush() {
    if (text.value.trim()) commit();
}

defineExpose({ focus: () => input.value?.focus(), flush });
</script>

<template>
    <div class="rcpt" @click="input?.focus()">
        <!-- 133: последняя фишка перед удалением подсвечивается; 136: у красной фишки
             раньше не было никакого объяснения, а отправка была заблокирована. -->
        <span
            v-for="(a, i) in modelValue"
            :key="a.mail + i"
            class="rcpt__chip"
            :class="{ 'rcpt__chip--bad': a.bad, 'rcpt__chip--warn': a.warn, 'rcpt__chip--armed': armed && i === modelValue.length - 1 }"
            :title="a.bad ? 'Это не похоже на адрес почты: нужен вид имя@домен.ру. Нажмите ✕ и наберите заново' : (a.warn || a.mail)"
        >
            <span>{{ a.name || a.mail }}</span>
            <span v-if="a.bad" class="rcpt__why">адрес не похож на почтовый</span>
            <button v-if="a.suggestion" type="button" class="rcpt__fix" :title="'Исправить на ' + a.mail.replace(/@.*$/, '@' + a.suggestion)" @click.stop="fix(i)">→ {{ a.suggestion }}?</button>
            <button type="button" title="Убрать" @click.stop="remove(i)">✕</button>
        </span>
        <input
            :id="inputId || undefined"
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
        <div v-if="sugg.length" ref="listEl" class="rcpt__list">
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
                    <div class="sug__sub">{{ s.mail }} · {{ { employee: 'рабочая почта', personal: 'личная почта сотрудника', recent: 'из переписки' }[s.kind] || 'адресная книга' }}</div>
                </span>
            </button>
        </div>
    </div>
</template>
