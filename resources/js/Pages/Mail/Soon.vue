<script setup>
import { usePage } from '@inertiajs/vue3';
import MailLayout from '../../Layouts/MailLayout.vue';

defineProps({ section: String });
const user = usePage().props.mailUser;

const copy = {
    calendar: ['Календарь', 'Встречи, приглашения, занятость коллег и общие календари — в этом же окне. Движок CalDAV подключается следующим шагом.'],
    contacts: ['Контакты', 'Личная адресная книга и общий список сотрудников с телефонами и должностями. Движок CardDAV подключается следующим шагом.'],
};
</script>

<template>
    <MailLayout :user="user">
        <main class="page" style="padding-top: 28px">
            <div class="page-head"><h1>{{ copy[section][0] }}</h1></div>
            <div class="card card--pad" style="max-width: 620px">
                <div class="card__title">Каркас</div>
                <p class="hint" style="margin: 0; line-height: 1.5">{{ copy[section][1] }}</p>
            </div>
        </main>
    </MailLayout>
</template>
