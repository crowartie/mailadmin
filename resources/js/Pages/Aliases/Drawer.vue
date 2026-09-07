<script setup>
import { useForm, router } from '@inertiajs/vue3';
import { onMounted, onBeforeUnmount, ref } from 'vue';
import Icon from '../../Components/Icon.vue';
import Toggle from '../../Components/Toggle.vue';

const props = defineProps({
    alias: Object,
    domains: Array,
});
const emit = defineEmits(['close']);

const localPart = ref(props.alias.isNew ? '' : props.alias.address.split('@')[0]);
const domain = ref(props.alias.isNew ? (props.domains[0] ?? '') : props.alias.address.split('@')[1]);

const form = useForm({
    address: props.alias.address,
    name: props.alias.name ?? '',
    targets: props.alias.targets?.length ? [...props.alias.targets] : [''],
    active: props.alias.active ?? true,
});

function submit() {
    if (props.alias.isNew) {
        form.transform((d) => ({ ...d, address: `${localPart.value.trim()}@${domain.value}` })).post('/aliases');
    } else {
        form.put(`/aliases/${props.alias.address}`);
    }
}

function destroy() {
    if (!confirm(`Удалить псевдоним ${props.alias.address}? Письма на него перестанут приниматься.`)) return;
    router.delete(`/aliases/${props.alias.address}`);
}

function onKey(e) { if (e.key === 'Escape') emit('close'); }
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div>
        <div class="drawer-backdrop" @click="emit('close')" />
        <aside class="drawer" role="dialog" aria-modal="true">
            <header class="drawer__head">
                <div class="avatar avatar--lg"><Icon name="at" :size="20" /></div>
                <div class="grow">
                    <h2>{{ alias.isNew ? 'Новый псевдоним' : alias.address }}</h2>
                    <div class="row__sub">{{ alias.isNew ? 'Адрес, который доставляется в другие ящики' : (alias.name || 'без описания') }}</div>
                </div>
                <button class="btn btn--sm btn--icon" type="button" title="Закрыть" @click="emit('close')"><Icon name="x" :size="18" /></button>
            </header>

            <form id="alias-form" class="drawer__body" @submit.prevent="submit">
                <div class="field__row">
                    <div class="field" style="width: 240px">
                        <label>Адрес</label>
                        <input v-model="localPart" class="input" :disabled="!alias.isNew" placeholder="sales" autofocus>
                    </div>
                    <span class="field__at">@</span>
                    <div class="field" style="width: 200px">
                        <label>Домен</label>
                        <select v-model="domain" class="input" :disabled="!alias.isNew">
                            <option v-for="d in domains" :key="d" :value="d">{{ d }}</option>
                        </select>
                    </div>
                </div>
                <p v-if="form.errors.address" class="error">{{ form.errors.address }}</p>

                <div class="field">
                    <label>Описание</label>
                    <input v-model="form.name" class="input" placeholder="Отдел продаж">
                </div>

                <div class="group-title">Доставлять на</div>
                <div v-for="(_, i) in form.targets" :key="i" class="field__row">
                    <input v-model="form.targets[i]" class="input" placeholder="info@innotec.su">
                    <button class="btn" type="button" :disabled="form.targets.length === 1" @click="form.targets.splice(i, 1)">Убрать</button>
                </div>
                <p v-if="form.errors.targets" class="error">{{ form.errors.targets }}</p>
                <template v-for="(_, i) in form.targets" :key="`te-${i}`">
                    <p v-if="form.errors[`targets.${i}`]" class="error">{{ form.errors[`targets.${i}`] }}</p>
                </template>
                <div><button class="btn" type="button" @click="form.targets.push('')"><Icon name="plus" :size="16" />Ещё адрес</button></div>
                <p class="hint">Можно указывать и внешние адреса — письмо уйдёт наружу от имени отправителя.</p>

                <div class="divider" />
                <Toggle v-model="form.active" label="Псевдоним включён" />
            </form>

            <footer class="drawer__foot">
                <button class="btn btn--primary" type="submit" form="alias-form" :disabled="form.processing">{{ alias.isNew ? 'Создать' : 'Сохранить' }}</button>
                <button class="btn" type="button" @click="emit('close')">Отмена</button>
                <span class="grow" />
                <button v-if="!alias.isNew" class="btn btn--danger" type="button" @click="destroy">Удалить</button>
            </footer>
        </aside>
    </div>
</template>
