<script setup>
// Условия и действия правила — один и тот же блок для самого правила и для каждого его уточнения
// (Настройки → Правила). Списки полей, операторов и действий приходят из useMailRules.
import Icon from '../Icon.vue';

defineProps({
    part: { type: Object, required: true },        // { match, conditions, actions }
    fields: Object, ops: Object, actions: Object,
    folders: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    opsFor: Function, onFieldChange: Function,
    maxConditions: { type: Number, default: 10 },
    maxActions: { type: Number, default: 6 },
    compact: Boolean,                              // уточнение: подписи короче
});
</script>

<template>
    <div class="field">
        <label>{{ compact ? 'Если' : 'Если' }} <select v-model="part.match" style="font: inherit; border: none; background: none; color: var(--accent-ink)"><option value="all">выполнены все условия</option><option value="any">выполнено любое условие</option></select></label>
        <div v-for="(c, ci) in part.conditions" :key="ci" class="rule__cond">
            <select v-model="c.field" class="input" style="max-width: 190px" @change="onFieldChange(c)"><option v-for="(t, k) in fields" :key="k" :value="k">{{ t }}</option></select>
            <input v-if="c.field === 'header'" v-model="c.header" class="input" placeholder="X-Priority" style="max-width: 160px">
            <!-- Список операторов строим по типу условия: v-show на <option> часть браузеров игнорирует. -->
            <select v-model="c.op" class="input" style="max-width: 170px"><option v-for="k in opsFor(c.field)" :key="k" :value="k">{{ ops[k] }}</option></select>
            <input v-model="c.value" class="input" :inputmode="c.field === 'size' ? 'numeric' : 'text'" :placeholder="c.field === 'size' ? '10240' : 'значение'">
            <button class="ib ib--sm" type="button" title="Убрать" @click="part.conditions.splice(ci, 1)" aria-label="Убрать"><Icon name="x" :size="14" /></button>
        </div>
        <button v-if="part.conditions.length < maxConditions" type="button" class="linklike" style="font-size: 13px; align-self: start" @click="part.conditions.push({ field: 'subject', op: 'contains', value: '' })">+ ещё условие</button>
        <span v-else class="hint" style="margin: 0">Условий не больше {{ maxConditions }}</span>
    </div>
    <div class="field">
        <label>То</label>
        <div v-for="(a, ai) in part.actions" :key="ai" class="rule__cond">
            <select v-model="a.type" class="input" style="max-width: 240px"><option v-for="(t, k) in actions" :key="k" :value="k">{{ t }}</option></select>
            <!-- Заглушка «— выберите —»: без неё правило выглядело настроенным, хотя папка не выбрана. -->
            <select v-if="a.type === 'move' || a.type === 'copy'" v-model="a.value" class="input">
                <option value="">— выберите папку —</option>
                <option v-for="f in folders" :key="f.path" :value="f.path">{{ '— '.repeat(f.depth) + f.name }}</option>
            </select>
            <select v-else-if="a.type === 'move_by_sender' || a.type === 'move_by_domain' || a.type === 'move_by_name'" v-model="a.value" class="input" :title="a.type === 'move_by_sender' ? 'Папка получит имя по части адреса до «@»: ivanov@polyus.com → «ivanov». Точки заменяются на «-». Папка создаётся сама при первом письме.' : a.type === 'move_by_domain' ? 'Папка получит имя по домену без зоны: polyus.com → «polyus». Папка создаётся сама при первом письме.' : 'Папка получит имя отправителя из поля «От»: «Иванов Иван» <ivanov@…> → «Иванов Иван». Если имени в письме нет (или в нём точка), берётся часть адреса до «@».'">
                <option value="">— среди своих папок (в корне) —</option>
                <option v-for="f in folders" :key="f.path" :value="f.path">внутри: {{ '— '.repeat(f.depth) + f.name }}</option>
            </select>
            <select v-else-if="a.type === 'label'" v-model="a.value" class="input">
                <option value="">— выберите метку —</option>
                <option v-for="l in labels" :key="l.id" :value="String(l.id)">{{ l.name }}</option>
            </select>
            <input v-else-if="a.type === 'forward' || a.type === 'forward_copy'" v-model="a.value" class="input" type="email" placeholder="кому@домен">
            <input v-else-if="a.type === 'reply'" v-model="a.value" class="input" placeholder="Текст ответа">
            <button class="ib ib--sm" type="button" title="Убрать" @click="part.actions.splice(ai, 1)" aria-label="Убрать"><Icon name="x" :size="14" /></button>
        </div>
        <button v-if="part.actions.length < maxActions" type="button" class="linklike" style="font-size: 13px; align-self: start" @click="part.actions.push({ type: 'label', value: '' })">+ ещё действие</button>
        <p v-if="part.actions.some((a) => ['move_by_sender', 'move_by_domain', 'move_by_name'].includes(a.type))" class="hint" style="margin: 6px 0 0">Папка для каждого отправителя создаётся сама при первом письме: по адресу — «ivanov» из ivanov@polyus.com, по домену — «polyus», по имени — «Иванов Иван» из поля «От». Так одно правило «От содержит @polyus.com» раскладывает письма всех сотрудников этой компании по персональным папкам.</p>
    </div>
</template>
