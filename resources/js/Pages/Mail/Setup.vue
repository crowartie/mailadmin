<script setup>
// Подключить телефон и программы: как «Интеграция с устройством» в Kerio. Открыта без входа.
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    user: String,
    settings: Object,
    domain: String,
    base: String,
    email: String,
    hosts: Object,
    qr: String,
    pageUrl: String,
    cert: Object,
    appPasswords: Boolean,
});

const email = ref(props.email || '');
const emailOk = computed(() => /^[^\s@]+@[^\s@]+$/.test(email.value));
const DEVICES = [['apple', 'iPhone, iPad, Mac'], ['android', 'Android'], ['windows', 'Outlook и Windows'], ['other', 'Другая программа']];
const device = ref('other');
onMounted(() => {
    const ua = navigator.userAgent || '';
    device.value = /iPhone|iPad|iPod|Macintosh/.test(ua) ? 'apple' : /Android/.test(ua) ? 'android' : /Windows/.test(ua) ? 'windows' : 'other';
});
const profileUrl = computed(() => `/mail/apple.mobileconfig${emailOk.value ? '?email=' + encodeURIComponent(email.value) : ''}`);
// Адрес подставляется в профиль и в QR: пересобираем страницу с ним, когда поле заполнено и ушло из фокуса.
function applyEmail() {
    if (emailOk.value && email.value !== props.email) router.get('/mail/setup', { email: email.value }, { preserveScroll: true, preserveState: true, only: ['email', 'qr', 'pageUrl'] });
}
const login = computed(() => (emailOk.value ? email.value : 'имя@' + props.domain));
</script>

<template>
    <Head title="Подключить телефон" />
    <MailLayout :user="user" :theme="settings?.theme">
        <div class="mset help setup">
            <div class="help__head">
                <Link :href="user ? '/mail' : '/mail/login'" class="ib" :title="user ? 'К письмам' : 'Ко входу'"><Icon name="back" :size="18" /></Link>
                <h1>Подключить телефон и программы</h1>
                <span class="grow" />
                <Link href="/mail/help#devices" class="btn btn--sm"><Icon name="info" :size="14" />Справка</Link>
            </div>

            <div class="mset__grid setup__grid">
                <div class="mset__body">
                    <section class="card mset__section help__section">
                        <h2>Ваш адрес</h2>
                        <p class="hint">Адрес подставится в профиль и в параметры ниже. Пароль — от почты<template v-if="appPasswords">, а если включена двухфакторная защита — пароль приложения из Настроек → Безопасность</template>.</p>
                        <input v-model.trim="email" class="input" style="max-width: 420px; font-size: 15px" :placeholder="'имя@' + domain" autocomplete="email" @blur="applyEmail" @keydown.enter.prevent="applyEmail">
                    </section>

                    <div class="tabs" style="margin-bottom: 4px">
                        <button v-for="[k, l] in DEVICES" :key="k" class="tabs__item" :class="{ 'tabs__item--on': device === k }" type="button" @click="device = k">{{ l }}</button>
                    </div>

                    <section v-if="device === 'apple'" class="card mset__section help__section">
                        <h2>iPhone, iPad, Mac</h2>
                        <article class="help__item">
                            <h3>Один профиль — почта, контакты и календарь</h3>
                            <ol class="hint" style="margin: 0; padding-left: 18px; line-height: 1.7">
                                <li>Откройте эту страницу в Safari на самом устройстве (со своего компьютера — наведите камеру на QR-код справа).</li>
                                <li>Нажмите «Установить профиль». Safari скажет, что профиль загружен.</li>
                                <li>iPhone/iPad: Настройки → «Профиль загружен» → Установить → введите код устройства → введите пароль от почты (или пароль приложения). Mac: Системные настройки → Основные → Профили.</li>
                                <li>Через минуту в «Почте», «Контактах» и «Календаре» появится учётная запись «{{ domain }}».</li>
                            </ol>
                            <p style="margin: 12px 0 0"><a :href="profileUrl" class="btn btn--primary" :class="{ 'btn--disabled': !emailOk }" :aria-disabled="!emailOk" @click="!emailOk && $event.preventDefault()"><Icon name="download" :size="15" />Установить профиль</a> <span v-if="!emailOk" class="hint" style="margin-left: 8px">сначала укажите адрес</span></p>
                            <p class="hint" style="margin-top: 10px">Профиль не содержит пароль — устройство спросит его само. Удалить всё разом: Настройки → Основные → VPN и управление устройством → профиль «{{ domain }}» → Удалить.</p>
                        </article>
                        <article class="help__item">
                            <h3>Вручную, без профиля</h3>
                            <p>Настройки → Почта → Учётные записи → Новая → Другое → Новая учётная запись: IMAP, параметры из таблицы ниже. Контакты и календарь — там же «Учётная запись CardDAV» и «Учётная запись CalDAV», сервер <code>{{ hosts.web }}</code>, логин и пароль от почты.</p>
                        </article>
                    </section>

                    <section v-if="device === 'android'" class="card mset__section help__section">
                        <h2>Android</h2>
                        <article class="help__item">
                            <h3>Почта</h3>
                            <p>Gmail, Samsung Email или любая почтовая программа → Добавить учётную запись → «Другая» / «Личная (IMAP)». Введите адрес <b>{{ login }}</b> и пароль — параметры серверов программа найдёт сама. Если спрашивает вручную: входящие <code>{{ hosts.imap }}</code> 993 SSL, исходящие <code>{{ hosts.smtp }}</code> 465 SSL (или 587 STARTTLS), логин — полный адрес.</p>
                        </article>
                        <article class="help__item">
                            <h3>Контакты, календарь и задачи</h3>
                            <p>Android сам не умеет CardDAV/CalDAV, нужна бесплатная программа <a href="https://play.google.com/store/apps/details?id=at.bitfire.davdroid" target="_blank" rel="noopener">DAVx⁵ в Google Play</a> (есть и в F-Droid, и в RuStore). В ней: «+» → «Вход с URL и именем пользователя» → адрес <code>{{ hosts.dav }}</code>, логин <b>{{ login }}</b>, пароль от почты → отметьте книги и календари, которые хотите видеть на телефоне. Задачи — приложение Tasks.org или OpenTasks через тот же DAVx⁵.</p>
                        </article>
                    </section>

                    <section v-if="device === 'windows'" class="card mset__section help__section">
                        <h2>Outlook и Windows</h2>
                        <article class="help__item">
                            <h3>Почта в Outlook</h3>
                            <p>Файл → Добавить учётную запись → введите <b>{{ login }}</b> → Outlook сам найдёт настройки (Autodiscover) и спросит пароль. Если предлагает выбрать тип — IMAP с параметрами из таблицы. Новый Outlook (Windows 11) подключает почту так же.</p>
                        </article>
                        <article class="help__item">
                            <h3>Календарь и контакты в Outlook</h3>
                            <p>Классический Outlook не умеет CalDAV/CardDAV сам. Поставьте бесплатный <a href="https://caldavsynchronizer.org/" target="_blank" rel="noopener">Outlook CalDav Synchronizer</a>, в нём: Synchronization Profiles → «+» → Generic CalDAV/CardDAV → DAV URL <code>{{ hosts.dav }}</code>, Username <b>{{ login }}</b>, пароль от почты → «Test or discover settings» → выберите календарь и книгу. В новом Outlook дополнения не работают — календарём и контактами пользуйтесь через веб-почту.</p>
                        </article>
                        <article class="help__item">
                            <h3>Почта Windows, Thunderbird</h3>
                            <p>Обе программы находят настройки сами по адресу. В Thunderbird календарь и контакты подключаются встроенно: Календарь → «+» → «В сети» → CalDAV, адрес <code>{{ hosts.dav }}</code>; Адресная книга → «+» → CardDAV, тот же адрес.</p>
                        </article>
                    </section>

                    <section class="card mset__section help__section">
                        <h2>Параметры для любой программы</h2>
                        <table class="help__table">
                            <tr><td>Логин</td><td><b>{{ login }}</b> — всегда полный адрес</td></tr>
                            <tr><td>Пароль</td><td>от почты<template v-if="appPasswords">; при включённой двухфакторной защите — пароль приложения</template></td></tr>
                            <tr><td>Входящие (IMAP)</td><td><code>{{ hosts.imap }}</code>, порт 993, SSL/TLS</td></tr>
                            <tr><td>Исходящие (SMTP)</td><td><code>{{ hosts.smtp }}</code>, порт 465 SSL или 587 STARTTLS, с авторизацией</td></tr>
                            <tr><td>Контакты (CardDAV)</td><td><code>{{ hosts.dav }}</code></td></tr>
                            <tr><td>Календарь и задачи (CalDAV)</td><td><code>{{ hosts.dav }}</code></td></tr>
                            <tr><td>Веб-почта</td><td><a :href="base + '/mail'">{{ base }}/mail</a></td></tr>
                        </table>
                        <p class="hint">POP3 не используйте: он забирает письма на одно устройство и не показывает папки. Exchange/ActiveSync на сервере нет — выбирайте IMAP.</p>
                    </section>

                    <section id="cert" class="card mset__section help__section">
                        <h2>Сертификат сервера</h2>
                        <template v-if="cert && cert.trusted">
                            <p class="hint">Сертификат выдан <b>{{ cert.issuer }}</b>{{ cert.until ? ', действует до ' + cert.until : '' }}. Телефоны и программы доверяют ему сами — ничего устанавливать не нужно. Скачать стоит только для старого устройства, которое ругается на сертификат.</p>
                        </template>
                        <template v-else>
                            <p class="hint">Сертификат{{ cert ? ' выдан «' + cert.issuer + '»' : '' }} не из общедоверенных: устройство может спросить, доверять ли ему. Скачайте файл и установите его как доверенный (iPhone: Настройки → Основные → VPN и управление устройством, затем Настройки → Основные → Об этом устройстве → Доверие сертификатам; Android: Настройки → Безопасность → Установить сертификат; Windows: двойной клик по файлу → Установить → «Доверенные корневые центры»).</p>
                        </template>
                        <p style="margin: 0"><a href="/mail/server.crt" class="btn btn--sm"><Icon name="download" :size="14" />Скачать сертификат (.crt)</a></p>
                    </section>
                </div>

                <aside class="card mset__section help__section setup__qr">
                    <h2>На телефоне</h2>
                    <p class="hint">Наведите камеру телефона на код — откроется эта страница{{ emailOk ? ' уже с вашим адресом' : '' }}. Дальше «Установить профиль» на iPhone или инструкция для Android.</p>
                    <div v-if="qr" class="setup__qrbox" v-html="qr" />
                    <p class="hint mono" style="word-break: break-all; font-size: 11.5px">{{ pageUrl }}</p>
                </aside>
            </div>
        </div>
    </MailLayout>
</template>
