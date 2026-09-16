<script setup>
// Безопасность: обзор, блокировки, журнал входов, 2FA, пароли приложений, сеансы, политики.
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import Toggle from '../../Components/Toggle.vue';

const props = defineProps({
    tab: String,
    available: Boolean,
    summary: Object,
    bans: Array,
    jails: Array,
    sessions: Array,
    policies: Object,
    fail2ban: Object,
    failedTop: Array,
    logins: Array,
    employees: Array,
    admins: Array,
    appPasswords: Array,
});

const TABS = [['overview', 'Обзор'], ['bans', 'Блокировки'], ['logins', 'Журнал входов'], ['twofa', 'Двухфакторная защита'], ['apppasswords', 'Пароли приложений'], ['sessions', 'Сеансы']];
const banIp = ref('');
const search = ref('');
const pol = useForm({ ...props.policies });

function post(url, data = {}) { router.post(url, data, { preserveScroll: true }); }
function when(iso) { if (!iso) return '—'; const d = new Date(iso); const diff = (Date.now() - d) / 60000; if (diff < 1) return 'сейчас'; if (diff < 60) return Math.round(diff) + ' мин назад'; if (diff < 1440) return Math.round(diff / 60) + ' ч назад'; return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' }) + ' ' + d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }); }
function ini(s) { const p = (s || '').replace(/@.*/, '').split(/[\s._-]+/).filter(Boolean); return p.slice(0, 2).map((x) => x[0].toUpperCase()).join('') || '?'; }
const filteredLogins = computed(() => (props.logins || []).filter((l) => !search.value || `${l.who} ${l.ip} ${l.label} ${l.where}`.toLowerCase().includes(search.value.toLowerCase())));
const filteredEmployees = computed(() => (props.employees || []).filter((e) => !search.value || `${e.username} ${e.name}`.toLowerCase().includes(search.value.toLowerCase())));
</script>

<template>
    <AppLayout title="Безопасность" search-placeholder="IP, сотрудник или устройство…">
        <template #actions>
            <span v-if="available" class="chip" :class="summary.banned ? 'chip--warn' : 'chip--ok'">fail2ban работает · {{ summary.banned ? summary.banned + ' блокир.' : 'блокировок нет' }}</span>
            <span v-else class="chip chip--no">fail2ban недоступен</span>
        </template>

        <div class="tabs">
            <Link v-for="[k, l] in TABS" :key="k" :href="k === 'overview' ? '/security' : `/security/${k}`" class="tabs__item" :class="{ 'tabs__item--on': tab === k }">{{ l }}<template v-if="k === 'bans' && summary.banned"> · {{ summary.banned }}</template></Link>
        </div>

        <!-- Обзор -->
        <template v-if="tab === 'overview'">
            <div class="tiles">
                <div class="tile"><div class="tile__value">{{ summary.banned ?? '—' }}</div><div class="tile__label">IP заблокировано</div><div class="tile__sub">за сутки: {{ summary.attempts }} неверных паролей</div></div>
                <div class="tile"><div class="tile__value" :class="{ 'tile__value--warn': summary.admins2fa < summary.admins }">{{ summary.admins2fa }} из {{ summary.admins }}</div><div class="tile__label">Админов с 2FA</div><div class="tile__sub">{{ policies.admin_2fa ? 'обязательна по политике' : 'политика выключена' }}</div></div>
                <div class="tile"><div class="tile__value" :class="{ 'tile__value--warn': summary.employeesNo2fa > 0 }">{{ summary.employeesNo2fa }}</div><div class="tile__label">Сотрудников без 2FA</div><div class="tile__sub">из {{ summary.employees }} · <Link href="/security/twofa">напомнить</Link></div></div>
                <div class="tile"><div class="tile__value" :class="{ 'tile__value--warn': summary.weak > 0 }">{{ summary.weak }}</div><div class="tile__label">Старых паролей</div><div class="tile__sub">не менялись дольше {{ policies.password_days }} дн</div></div>
            </div>

            <div class="grid-set">
                <div class="card card--pad">
                    <div class="card__title">Блокировки</div>
                    <div v-if="!bans.length" class="empty">Сейчас никто не заблокирован</div>
                    <div v-for="b in bans" :key="b.ip + b.jail" class="kv"><span class="mono" style="color: inherit">{{ b.ip }}</span><span class="row__sub" style="flex: 1; margin-left: 12px">{{ b.why }} · {{ b.where }}</span><button class="btn btn--sm" type="button" @click="post('/security/unban', { ip: b.ip })">Снять</button></div>
                    <div v-if="failedTop.length" style="margin-top: 12px">
                        <div class="group-title">Больше всего неверных паролей за сутки</div>
                        <div v-for="f in failedTop" :key="f.ip" class="kv"><span class="mono" style="color: inherit">{{ f.ip }}</span><span style="display: flex; gap: 8px; align-items: center"><b>{{ f.n }}</b><button v-if="!bans.some((b) => b.ip === f.ip)" class="btn btn--sm" type="button" @click="post('/security/ban', { ip: f.ip })">Заблокировать</button></span></div>
                    </div>
                    <div class="row__sub" style="margin-top: 12px">Правило: {{ fail2ban.maxretry }} неверных паролей за {{ fail2ban.findtime }} минут — блок на {{ fail2ban.bantime_hours }} ч. <Link href="/security/bans">Все блокировки</Link></div>
                </div>
                <div class="card card--pad">
                    <div class="card__title" style="display: flex; align-items: center">Активные сеансы <span style="flex: 1" /><Link href="/security/sessions" class="hint">Все {{ sessions.length }}</Link></div>
                    <div v-if="!sessions.length" class="empty">Сейчас никто не подключён</div>
                    <div v-for="s in sessions.slice(0, 6)" :key="s.id" class="kv">
                        <span style="color: inherit; min-width: 0; overflow: hidden; text-overflow: ellipsis"><b>{{ s.user.split('@')[0] }}</b> <span class="row__sub">{{ s.device }}</span></span>
                        <span style="display: flex; gap: 8px; align-items: center; white-space: nowrap"><span class="mono faint">{{ s.ip }}</span><span class="faint">{{ s.seen ? when(s.seen) : '' }}</span><button v-if="!s.me" class="btn btn--sm" type="button" @click="post('/security/kick', { id: s.id })">Завершить</button><span v-else class="chip chip--acc">это вы</span></span>
                    </div>
                </div>
                <form class="card card--pad span-2" @submit.prevent="pol.post('/security/policies', { preserveScroll: true })">
                    <div class="card__title">Политики</div>
                    <div class="toggles--3">
                        <div class="field__row"><span>Пароль не короче</span><input v-model="pol.min_password" class="input" type="number" min="6" max="64" style="width: 70px; height: 34px"><span>символов, смена раз в</span><input v-model="pol.password_days" class="input" type="number" min="0" style="width: 80px; height: 34px"><span>дн</span></div>
                        <Toggle v-model="pol.admin_2fa" label="2FA обязательна для администраторов" />
                        <Toggle v-model="pol.all_2fa_internet" label="2FA обязательна для всех при входе из интернета" />
                        <Toggle v-model="pol.notify_new_device" label="Уведомлять сотрудника о входе с нового устройства" />
                        <Toggle v-model="pol.app_passwords" label="Разрешить пароли приложений для IMAP/SMTP" />
                        <Toggle v-model="pol.telegram_security" label="Сообщать в Telegram о блокировках и входах админов" />
                    </div>
                    <div style="margin-top: 14px"><button class="btn btn--primary" type="submit" :disabled="pol.processing">Сохранить</button></div>
                </form>
            </div>
        </template>

        <!-- Блокировки -->
        <template v-if="tab === 'bans'">
            <div class="grid-set">
                <div class="card card--flush">
                    <div class="thead" style="grid-template-columns: 150px minmax(0, 1fr) 120px auto"><span>IP</span><span>За что</span><span>Откуда</span><span /></div>
                    <div v-for="b in bans" :key="b.ip + b.jail" class="row" style="grid-template-columns: 150px minmax(0, 1fr) 120px auto"><span class="mono">{{ b.ip }}</span><span class="row__sub">{{ b.why }} <span class="faint">({{ b.jail }})</span></span><span class="row__sub">{{ b.where }}</span><span style="display: flex; gap: 6px"><button class="btn btn--sm" type="button" @click="post('/security/unban', { ip: b.ip })">Снять</button><button class="btn btn--sm" type="button" title="Больше не блокировать этот адрес" @click="post('/security/ignore', { ip: b.ip })">В белый список</button></span></div>
                    <div v-if="!bans.length" class="empty">Сейчас никто не заблокирован</div>
                </div>
                <div style="display: flex; flex-direction: column; gap: 16px">
                    <div class="card card--pad">
                        <div class="card__title">Заблокировать вручную</div>
                        <form class="field__row" @submit.prevent="post('/security/ban', { ip: banIp }); banIp = ''"><input v-model="banIp" class="input" placeholder="IP-адрес" required><button class="btn btn--primary" type="submit">Заблокировать</button></form>
                        <p class="hint">Блок на {{ fail2ban.bantime_hours }} ч, как и автоматический. Белый список снимает блокировки навсегда — используйте для своих адресов.</p>
                    </div>
                    <div class="card card--pad">
                        <div class="card__title">Правила fail2ban</div>
                        <div v-for="j in jails" :key="j.jail" class="kv"><span style="color: inherit">{{ j.title }}</span><span class="row__sub">сейчас {{ j.banned }} · всего {{ j.total }} · неудач {{ j.failed }}</span></div>
                        <p class="hint" style="margin: 10px 0 0">{{ fail2ban.maxretry }} неверных паролей за {{ fail2ban.findtime }} минут — блок на {{ fail2ban.bantime_hours }} ч.</p>
                    </div>
                </div>
            </div>
        </template>

        <!-- Журнал входов -->
        <template v-if="tab === 'logins'">
            <div class="card card--flush">
                <div style="padding: 10px 18px; border-bottom: 1px solid var(--border)"><input v-model="search" class="input" type="search" placeholder="Сотрудник, IP, результат…" style="height: 34px; width: 320px"></div>
                <div class="thead" style="grid-template-columns: 130px minmax(0, 1fr) 140px 110px minmax(0, 1.2fr)"><span>Когда</span><span>Кто</span><span>Откуда</span><span>Где</span><span>Результат</span></div>
                <div v-for="(l, i) in filteredLogins" :key="i" class="row" style="grid-template-columns: 130px minmax(0, 1fr) 140px 110px minmax(0, 1.2fr); padding: 9px 18px">
                    <span class="faint" style="font-size: 12.5px">{{ new Date(l.at).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) }}</span>
                    <span class="mono ellipsis">{{ l.who }}</span><span class="mono faint">{{ l.ip }}</span><span class="row__sub">{{ l.where }}</span>
                    <span style="display: flex; gap: 8px; align-items: center"><span class="chip" :class="`chip--${l.kind}`">{{ l.label }}</span><span v-if="l.agent" class="faint ellipsis" :title="l.agent" style="font-size: 12px">{{ l.agent.slice(0, 60) }}</span></span>
                </div>
                <div v-if="!filteredLogins.length" class="empty">Записей нет</div>
            </div>
        </template>

        <!-- 2FA -->
        <template v-if="tab === 'twofa'">
            <div class="grid-set">
                <div class="card card--flush">
                    <div style="padding: 10px 18px; border-bottom: 1px solid var(--border); display: flex; gap: 8px; align-items: center">
                        <input v-model="search" class="input" type="search" placeholder="Сотрудник…" style="height: 34px; width: 260px">
                        <span style="flex: 1" />
                        <button class="btn btn--sm" type="button" @click="post('/security/require-2fa', { all: true })">Потребовать у всех</button>
                    </div>
                    <div v-for="e in filteredEmployees" :key="e.username" class="row" style="grid-template-columns: 36px minmax(0, 1fr) 130px auto">
                        <span class="avatar">{{ ini(e.name) }}</span>
                        <div style="min-width: 0"><b>{{ e.name }}</b><div class="row__sub mono">{{ e.username }}</div></div>
                        <span><span class="chip" :class="e.enabled ? 'chip--ok' : e.required ? 'chip--acc' : 'chip--warn'">{{ e.enabled ? 'включена' : e.required ? 'при следующем входе' : 'выключена' }}</span></span>
                        <span style="display: flex; gap: 6px">
                            <button v-if="!e.enabled && !e.required" class="btn btn--sm" type="button" @click="post('/security/require-2fa', { user: e.username })">Потребовать</button>
                            <button v-if="!e.enabled && e.required" class="btn btn--sm" type="button" @click="post('/security/require-2fa', { user: e.username, off: true })">Снять требование</button>
                            <button v-if="e.enabled" class="btn btn--sm" type="button" @click="confirm(`Сбросить защиту у ${e.name}? Потерянный телефон — единственная причина это делать.`) && post('/security/reset-2fa', { user: e.username })">Сбросить</button>
                        </span>
                    </div>
                </div>
                <div class="card card--pad">
                    <div class="card__title">Администраторы</div>
                    <div v-for="a in admins" :key="a.email" class="kv"><span style="color: inherit"><b>{{ a.name || a.email }}</b> <span class="row__sub">{{ a.email }}</span></span><span class="chip" :class="a.enabled ? 'chip--ok' : 'chip--warn'">{{ a.enabled ? '2FA есть' : 'без 2FA' }}</span></div>
                    <p class="hint" style="margin-top: 10px">Администратор без защиты при следующем входе увидит окно подключения, если включена политика «2FA обязательна для администраторов». Сотрудник настраивает защиту в веб-почте: Настройки → Безопасность.</p>
                </div>
            </div>
        </template>

        <!-- Пароли приложений -->
        <template v-if="tab === 'apppasswords'">
            <div class="card card--flush">
                <div class="thead" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 140px 140px auto"><span>Сотрудник</span><span>Устройство</span><span>Создан</span><span>Использован</span><span /></div>
                <div v-for="p in appPasswords" :key="p.id" class="row" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 140px 140px auto"><span class="mono ellipsis">{{ p.user }}</span><span>{{ p.name }}</span><span class="faint">{{ when(p.created) }}</span><span class="faint">{{ p.lastUsed ? when(p.lastUsed) : 'ещё нет' }}</span><Link class="btn btn--sm" :href="`/security/app-passwords/${p.id}`" method="delete" as="button" preserve-scroll>Отозвать</Link></div>
                <div v-if="!appPasswords.length" class="empty">Паролей приложений пока нет. Сотрудник создаёт их в веб-почте: Настройки → Безопасность.</div>
            </div>
            <p class="hint">Пароль приложения — отдельный пароль для телефона или Outlook: его можно отозвать, не меняя основной. Dovecot проверяет его наравне с основным.</p>
        </template>

        <!-- Сеансы -->
        <template v-if="tab === 'sessions'">
            <div class="card card--flush">
                <div class="thead" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr) 150px 120px auto"><span>Сотрудник</span><span>Устройство</span><span>IP</span><span>Активность</span><span /></div>
                <div v-for="s in sessions" :key="s.id" class="row" style="grid-template-columns: minmax(0, 1fr) minmax(0, 1.3fr) 150px 120px auto"><span class="mono ellipsis">{{ s.user }}</span><span class="row__sub">{{ s.device }}</span><span class="mono faint">{{ s.ip }}</span><span class="faint">{{ s.seen ? when(s.seen) : 'подключён' }}</span><span><button v-if="!s.me" class="btn btn--sm" type="button" @click="post('/security/kick', { id: s.id })">Завершить</button><span v-else class="chip chip--acc">это вы</span></span></div>
                <div v-if="!sessions.length" class="empty">Сейчас никто не подключён</div>
            </div>
            <p class="hint">Веб-сеансы — из нашей базы, IMAP/POP3 — из Dovecot (doveadm who). Завершение IMAP-сеанса разрывает соединение; телефон переподключится, если пароль не менялся.</p>
        </template>
    </AppLayout>
</template>
