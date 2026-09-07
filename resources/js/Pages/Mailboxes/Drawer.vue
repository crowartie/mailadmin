<script setup>
import { useForm, router } from '@inertiajs/vue3';
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import Icon from '../../Components/Icon.vue';
import Toggle from '../../Components/Toggle.vue';

const props = defineProps({
    mailbox: Object,
    domains: Array,
    serviceFlags: Array,
});
const emit = defineEmits(['close']);

// Порядок вкладок повторяет карточку пользователя в Kerio.
const tabs = [
    { key: 'general', label: 'Общие' },
    { key: 'addresses', label: 'Адреса' },
    { key: 'contact', label: 'Контакт' },
    { key: 'forwarding', label: 'Переадресация' },
    { key: 'groups', label: 'Группы' },
    { key: 'rights', label: 'Права' },
    { key: 'quota', label: 'Квота' },
];
const activeTab = ref('general');
const showPassword = ref(false);

const form = useForm({
    password: '',
    name: props.mailbox.name ?? '',
    quota: props.mailbox.quota ?? 1024,
    active: props.mailbox.active ?? true,
    first_name: props.mailbox.first_name ?? '',
    last_name: props.mailbox.last_name ?? '',
    telephone: props.mailbox.telephone ?? '',
    mobile: props.mailbox.mobile ?? '',
    department: props.mailbox.department ?? '',
    rank: props.mailbox.rank ?? '',
    employeeid: props.mailbox.employeeid ?? '',
    recovery_email: props.mailbox.recovery_email ?? '',
    forwardings: [...(props.mailbox.forwardings ?? [])],
    keep_copy: props.mailbox.keep_copy ?? true,
    aliases: [...(props.mailbox.aliases ?? [])],
    isadmin: props.mailbox.isadmin ?? false,
    isglobaladmin: props.mailbox.isglobaladmin ?? false,
    services: { ...(props.mailbox.services ?? {}) },
});

const initials = computed(() => {
    const parts = (props.mailbox.name || props.mailbox.username).replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean);
    return parts.slice(0, 2).map((p) => p[0].toUpperCase()).join('') || '?';
});

function generatePassword() {
    const alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
    const bytes = new Uint32Array(14);
    crypto.getRandomValues(bytes);
    form.password = Array.from(bytes, (n) => alphabet[n % alphabet.length]).join('');
    showPassword.value = true;
}

function submit() {
    form.put(`/mailboxes/${props.mailbox.username}`, { preserveScroll: true });
}

function destroy() {
    if (!confirm(`Удалить ${props.mailbox.username}? Письма будут помечены к удалению почтовым сервером.`)) return;
    router.delete(`/mailboxes/${props.mailbox.username}`);
}

const tabFields = {
    general: ['password', 'name', 'active'],
    addresses: ['aliases'],
    contact: ['first_name', 'last_name', 'telephone', 'mobile', 'department', 'rank', 'employeeid', 'recovery_email'],
    forwarding: ['forwardings', 'keep_copy'],
    groups: [],
    rights: ['services', 'isadmin', 'isglobaladmin'],
    quota: ['quota'],
};
const tabHasError = (key) =>
    tabFields[key].some((f) => Object.keys(form.errors).some((e) => e === f || e.startsWith(`${f}.`)));

function onKey(e) {
    if (e.key === 'Escape') emit('close');
}
onMounted(() => window.addEventListener('keydown', onKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div>
        <div class="drawer-backdrop" @click="emit('close')" />
        <aside class="drawer" role="dialog" aria-modal="true" :aria-label="mailbox.username">
            <header class="drawer__head">
                <div class="avatar avatar--lg">{{ initials }}</div>
                <div class="grow">
                    <h2>{{ mailbox.name || mailbox.username }}</h2>
                    <div class="row__sub mono">{{ mailbox.username }}</div>
                </div>
                <span class="chip" :class="mailbox.active ? 'chip--ok' : 'chip--off'">{{ mailbox.active ? 'активен' : 'заблокирован' }}</span>
                <button class="btn btn--sm btn--icon" type="button" title="Закрыть" @click="emit('close')"><Icon name="x" :size="18" /></button>
            </header>

            <div class="tabs">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="tabs__item"
                    :class="{ 'tabs__item--on': activeTab === tab.key, 'tabs__item--error': tabHasError(tab.key) }"
                    @click="activeTab = tab.key"
                >
                    {{ tab.label }}
                </button>
            </div>

            <form id="mailbox-form" class="drawer__body" @submit.prevent="submit">
                <!-- Общие -->
                <template v-if="activeTab === 'general'">
                    <div class="field__row">
                        <div class="field" style="width: 240px"><label>Адрес</label><input class="input" :value="mailbox.local_part" disabled></div>
                        <span class="field__at">@</span>
                        <div class="field" style="width: 200px"><label>Домен</label><input class="input" :value="mailbox.domain" disabled></div>
                    </div>
                    <p class="hint" style="margin-top: -8px">Адрес изменить нельзя — по нему разложена почта на диске.</p>

                    <div class="field"><label>Полное имя</label><input v-model="form.name" class="input"></div>

                    <div class="field">
                        <label>Новый пароль</label>
                        <div class="field__row">
                            <input v-model="form.password" class="input" :type="showPassword ? 'text' : 'password'" placeholder="оставьте пустым, чтобы не менять" autocomplete="new-password">
                            <button class="btn" type="button" @click="generatePassword">Сгенерировать</button>
                            <button class="btn btn--icon" type="button" :title="showPassword ? 'Скрыть' : 'Показать'" @click="showPassword = !showPassword"><Icon name="check" :size="16" /></button>
                        </div>
                        <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>
                        <p v-if="mailbox.passwordChanged" class="hint">Пароль менялся {{ mailbox.passwordChanged }}</p>
                    </div>

                    <Toggle v-model="form.active" label="Учётная запись включена" />

                    <div class="divider" />
                    <div class="kv"><span>Создан</span><b>{{ mailbox.created }}</b></div>
                    <div class="kv"><span>Каталог</span><b class="mono" style="font-weight: 400">{{ mailbox.maildir }}</b></div>
                </template>

                <!-- Адреса -->
                <template v-if="activeTab === 'addresses'">
                    <p class="hint">Дополнительные адреса, письма с которых попадают в этот ящик.</p>
                    <div v-for="(_, i) in form.aliases" :key="i" class="field__row">
                        <input v-model="form.aliases[i]" class="input" placeholder="sales@innotec.su">
                        <button class="btn" type="button" @click="form.aliases.splice(i, 1)">Убрать</button>
                    </div>
                    <template v-for="(_, i) in form.aliases" :key="`ae-${i}`">
                        <p v-if="form.errors[`aliases.${i}`]" class="error">{{ form.errors[`aliases.${i}`] }}</p>
                    </template>
                    <div><button class="btn" type="button" @click="form.aliases.push('')"><Icon name="plus" :size="16" />Добавить адрес</button></div>
                </template>

                <!-- Контакт -->
                <template v-if="activeTab === 'contact'">
                    <p class="hint">Эти поля попадают в общий список адресов и в общую адресную книгу.</p>
                    <div class="grid-2">
                        <div class="field"><label>Фамилия</label><input v-model="form.last_name" class="input"></div>
                        <div class="field"><label>Имя</label><input v-model="form.first_name" class="input"></div>
                        <div class="field"><label>Должность</label><input v-model="form.rank" class="input"></div>
                        <div class="field"><label>Подразделение</label><input v-model="form.department" class="input"></div>
                        <div class="field"><label>Телефон</label><input v-model="form.telephone" class="input"></div>
                        <div class="field"><label>Мобильный</label><input v-model="form.mobile" class="input"></div>
                        <div class="field"><label>Табельный номер</label><input v-model="form.employeeid" class="input"></div>
                        <div class="field"><label>Контактный адрес вне почты</label><input v-model="form.recovery_email" class="input"></div>
                    </div>
                    <p v-if="form.errors.recovery_email" class="error">{{ form.errors.recovery_email }}</p>
                </template>

                <!-- Переадресация -->
                <template v-if="activeTab === 'forwarding'">
                    <div v-for="(_, i) in form.forwardings" :key="i" class="field__row">
                        <input v-model="form.forwardings[i]" class="input" placeholder="user@example.com">
                        <button class="btn" type="button" @click="form.forwardings.splice(i, 1)">Убрать</button>
                    </div>
                    <template v-for="(_, i) in form.forwardings" :key="`fe-${i}`">
                        <p v-if="form.errors[`forwardings.${i}`]" class="error">{{ form.errors[`forwardings.${i}`] }}</p>
                    </template>
                    <div><button class="btn" type="button" @click="form.forwardings.push('')"><Icon name="plus" :size="16" />Добавить адрес</button></div>
                    <Toggle v-model="form.keep_copy" label="Доставлять и в ящик, и на адреса пересылки" />
                </template>

                <!-- Группы -->
                <template v-if="activeTab === 'groups'">
                    <p class="hint">Рассылки, в которые входит адрес. Состав правится в разделе «Рассылки».</p>
                    <ul v-if="mailbox.memberships?.length" class="plain-list">
                        <li v-for="m in mailbox.memberships" :key="m" class="mono">{{ m }}</li>
                    </ul>
                    <p v-else class="empty" style="padding: 20px 0; text-align: left">Не входит ни в одну рассылку</p>
                </template>

                <!-- Права -->
                <template v-if="activeTab === 'rights'">
                    <div class="group-title">Доступ к службам</div>
                    <Toggle v-for="flag in serviceFlags" :key="flag.key" v-model="form.services[flag.key]" :label="flag.label" />
                    <p class="hint">Выключенная служба отдаёт клиенту отказ авторизации, а не «нет писем».</p>
                    <div class="divider" />
                    <div class="group-title">Администрирование</div>
                    <Toggle v-model="form.isadmin" label="Администратор своего домена" />
                    <Toggle v-model="form.isglobaladmin" label="Администратор всего сервера" />
                </template>

                <!-- Квота -->
                <template v-if="activeTab === 'quota'">
                    <div class="field" style="width: 220px">
                        <label>Размер ящика, МБ</label>
                        <input v-model.number="form.quota" class="input" type="number" min="0" step="256">
                        <p v-if="form.errors.quota" class="error">{{ form.errors.quota }}</p>
                    </div>
                    <p class="hint">0 — без ограничения. Ограничения по числу писем нет: сервер считает только байты.</p>
                </template>
            </form>

            <footer class="drawer__foot">
                <button class="btn btn--primary" type="submit" form="mailbox-form" :disabled="form.processing">Сохранить</button>
                <button class="btn" type="button" @click="emit('close')">Отмена</button>
                <span class="grow" />
                <button class="btn btn--danger" type="button" @click="destroy">Удалить</button>
            </footer>
        </aside>
    </div>
</template>
