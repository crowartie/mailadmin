<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import Toggle from '../../Components/Toggle.vue';

const props = defineProps({
    domains: Array,
    defaults: Object,
    units: Array,
});

const form = useForm({
    local_part: '',
    domain: props.domains[0]?.value ?? '',
    password: '',
    name: '',
    quota: props.defaults.quota,
    active: true,
    last_name: '',
    first_name: '',
    rank: '',
    unit_id: null,
    is_service: false,
    telephone: '',
    mobile: '',
    recovery_email: '',
    aliases: [],
    keep_copy: true,
    services: { ...props.defaults.services },
});

const showPassword = ref(false);
const extraAddress = ref('');
const nameTouched = ref(false);

// Пока отображаемое имя не трогали руками, собираем его из фамилии и имени.
watch([() => form.last_name, () => form.first_name], ([last, first]) => {
    if (!nameTouched.value) form.name = [last, first].filter(Boolean).join(' ');
});

function generatePassword() {
    const alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
    const bytes = new Uint32Array(14);
    crypto.getRandomValues(bytes);
    form.password = Array.from(bytes, (n) => alphabet[n % alphabet.length]).join('');
    showPassword.value = true;
}

function submit() {
    form.transform((data) => ({ ...data, aliases: extraAddress.value ? [extraAddress.value] : [] })).post('/mailboxes');
}
</script>

<template>
    <AppLayout title="Новый сотрудник">
        <form class="form-layout" @submit.prevent="submit">
            <div class="card form-card">
                <div class="group-title">Учётная запись</div>

                <div class="field__row">
                    <div class="field" style="width: 300px"><label>Адрес</label><input v-model="form.local_part" class="input" placeholder="ivanov" autofocus></div>
                    <span class="field__at">@</span>
                    <div class="field" style="width: 220px">
                        <label>Домен</label>
                        <select v-model="form.domain" class="input">
                            <option v-for="d in domains" :key="d.value" :value="d.value">{{ d.label }}</option>
                        </select>
                    </div>
                </div>
                <p v-if="form.errors.local_part" class="error">{{ form.errors.local_part }}</p>

                <div class="field__row">
                    <div class="field" style="width: 400px">
                        <label>Пароль</label>
                        <input v-model="form.password" class="input" :type="showPassword ? 'text' : 'password'" autocomplete="new-password">
                    </div>
                    <button class="btn" type="button" @click="generatePassword">Сгенерировать</button>
                    <button class="btn" type="button" @click="showPassword = !showPassword">{{ showPassword ? 'Скрыть' : 'Показать' }}</button>
                </div>
                <p v-if="form.errors.password" class="error">{{ form.errors.password }}</p>

                <div class="field__row" style="align-items: center">
                    <div class="field" style="width: 200px"><label>Размер ящика, МБ</label><input v-model.number="form.quota" class="input" type="number" min="0" step="256"></div>
                    <Toggle v-model="form.active" label="Учётная запись включена" style="padding-top: 22px" />
                </div>
                <div class="field">
                    <Toggle v-model="form.is_service" label="Служебный ящик, а не сотрудник (info@, сканер, принтер)" />
                    <p class="hint" style="margin: 4px 0 0">Служебный ящик не попадает в общую книгу «Сотрудники», не считается в статистике защиты и не предлагается руководителем отдела.</p>
                </div>

                <div class="divider" />

                <div class="group-title">Контактные данные</div>
                <p class="hint" style="margin-top: -8px">Попадают в общий список адресов и в общую адресную книгу — их видят все сотрудники.</p>

                <div class="grid-2">
                    <div class="field"><label>Фамилия</label><input v-model="form.last_name" class="input"></div>
                    <div class="field"><label>Имя</label><input v-model="form.first_name" class="input"></div>
                    <div class="field"><label>Должность</label><input v-model="form.rank" class="input"></div>
                    <div class="field"><label>Подразделение</label><select v-model="form.unit_id" class="input"><option :value="null">— без подразделения —</option><option v-for="u in units || []" :key="u.id" :value="u.id">{{ ' '.repeat(u.depth * 3) }}{{ u.name }}</option></select></div>
                    <div class="field"><label>Телефон</label><input v-model="form.telephone" class="input"></div>
                    <div class="field"><label>Мобильный</label><input v-model="form.mobile" class="input"></div>
                </div>

                <div class="field">
                    <label>Отображаемое имя</label>
                    <input v-model="form.name" class="input" placeholder="заполняется само из фамилии и имени" @input="nameTouched = true">
                </div>
            </div>

            <div class="side-stack">
                <div class="card form-card" style="gap: 14px">
                    <div class="group-title">Адреса</div>
                    <div class="field">
                        <label>Дополнительный адрес</label>
                        <input v-model="extraAddress" class="input" placeholder="i.ivanov@innotec.su">
                        <p v-if="form.errors['aliases.0']" class="error">{{ form.errors['aliases.0'] }}</p>
                    </div>
                    <div class="field">
                        <label>Контактный адрес вне почты</label>
                        <input v-model="form.recovery_email" class="input" placeholder="ivanov@example.com">
                        <p v-if="form.errors.recovery_email" class="error">{{ form.errors.recovery_email }}</p>
                        <p class="hint">Личный или резервный ящик — для связи и восстановления доступа.</p>
                    </div>
                </div>

                <div class="card card--pad">
                    <div class="card__title">Что произойдёт после создания</div>
                    <p class="hint" style="margin: 0; line-height: 1.5">
                        Ящик появится в общем списке адресов, карточка сотрудника — в общей адресной книге.
                        Права, службы и пересылки выдаются по умолчанию, их меняют в карточке.
                    </p>
                </div>

                <div style="display: flex; gap: 10px">
                    <button class="btn btn--primary" type="submit" style="flex: 1; height: 44px" :disabled="form.processing">
                        <Icon name="plus" :size="16" />Создать сотрудника
                    </button>
                    <a class="btn" href="/mailboxes" style="height: 44px">Отмена</a>
                </div>
            </div>
        </form>
    </AppLayout>
</template>
