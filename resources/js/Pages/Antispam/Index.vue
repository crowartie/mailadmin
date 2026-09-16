<script setup>
// Антиспам: сводка за сутки, обучение Bayes, внешние базы, пороги, общие правила.
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import { http } from '../../admin/http';

const props = defineProps({
    stats: Object, bayes: Object, learning: Object, net: Object, levels: Object, rules: Object, available: Boolean,
});

const stats = ref(props.stats);
const bayes = ref(props.bayes);
const learning = ref(props.learning);
const net = ref(props.net);
const levels = ref({ ...props.levels });
const rules = ref(props.rules);
const busy = ref(false);
const flash = ref(null);
let timer = null;

const t = computed(() => stats.value.today || {});
const y = computed(() => stats.value.yesterday || {});
const caught = computed(() => (t.value.spammy || 0) + (t.value.spam || 0) + (t.value.toJunk || 0));
const caughtY = computed(() => (y.value.spammy || 0) + (y.value.spam || 0) + (y.value.toJunk || 0));

function say(text, error = false) { flash.value = { text, error }; setTimeout(() => { flash.value = null; }, 4500); }
function delta(a, b) { if (!b && !a) return ''; const d = (a || 0) - (b || 0); return d === 0 ? 'как вчера' : (d > 0 ? '+' : '') + d + ' к вчера'; }
function when(ts) { return ts ? new Date(ts * 1000).toLocaleDateString('ru-RU', { day: '2-digit', month: 'long' }) : '—'; }

async function refresh(fresh = false) {
    try {
        const r = await http('GET', '/antispam/json' + (fresh ? '?fresh=1' : ''));
        stats.value = r.stats; bayes.value = r.bayes; learning.value = r.learning; net.value = r.net; rules.value = r.rules;
        if (!busy.value) levels.value = { ...r.levels };
    } catch (e) { say(e.message, true); }
}
async function saveLevels() {
    busy.value = true;
    try {
        const r = await http('POST', '/antispam/levels', { tag2: Number(levels.value.tag2), kill: Number(levels.value.kill) });
        levels.value = { ...r.levels };
        say('Пороги сохранены, Amavis перезапущен');
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}
async function learnNow() {
    busy.value = true;
    try {
        const r = await http('POST', '/antispam/learn');
        bayes.value = r.bayes; learning.value = r.learning;
        say('Очередь обучения обработана');
    } catch (e) { say(e.message, true); } finally { busy.value = false; }
}
onMounted(() => { timer = setInterval(() => refresh(false), 60000); });
onBeforeUnmount(() => clearInterval(timer));

const tiles = computed(() => [
    { label: 'Принято писем', value: t.value.clean + caught.value || 0, sub: delta(t.value.clean + caught.value, y.value.clean + caughtY.value) },
    { label: 'Поймано спама', value: caught.value, sub: delta(caught.value, caughtY.value), kind: caught.value ? 'warn' : '' },
    { label: 'Отбито до приёма', value: (t.value.dnsbl || 0) + (t.value.rejects || 0), sub: `чёрные списки ${t.value.dnsbl || 0} · отказы ${t.value.rejects || 0}` },
    { label: 'Серый список', value: t.value.grey || 0, sub: 'повторят позже' },
    { label: 'Bayes сработал', value: t.value.bayes || 0, sub: bayes.value.active ? 'фильтр обучен' : 'фильтр ещё учится', kind: bayes.value.active ? '' : 'warn' },
]);
</script>

<template>
    <AppLayout title="Антиспам" :count="stats.at ? 'данные на ' + new Date(stats.at).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : ''">
        <template #actions>
            <button class="btn" type="button" :disabled="busy" @click="refresh(true)"><Icon name="refresh" :size="16" />Обновить</button>
        </template>

        <div v-if="!available" class="card card--pad" style="border-color: var(--warn)">Обёртка mailadmin-ctl не установлена на сервере — данные недоступны.</div>
        <transition name="flash"><div v-if="flash" class="flash" :class="{ 'flash--error': flash.error }">{{ flash.text }}</div></transition>

        <div class="tiles tiles--5">
            <div v-for="tile in tiles" :key="tile.label" class="tile">
                <div class="tile__value" :class="{ 'tile__value--warn': tile.kind === 'warn' }">{{ tile.value }}</div>
                <div class="tile__label">{{ tile.label }}</div>
                <div class="tile__sub">{{ tile.sub }}</div>
            </div>
        </div>

        <div class="grid-2-1">
            <div class="card card--pad">
                <div class="card__title">Что поймано за сегодня</div>
                <div class="kv"><span>Помечено спамом (Amavis, выше порога)</span><b>{{ t.spammy || 0 }} + {{ t.spam || 0 }}</b></div>
                <div class="kv"><span>Уложено в «Спам» общими правилами при доставке</span><b>{{ t.toJunk || 0 }}</b></div>
                <div class="kv"><span>Уложено в «Рассылки» общими правилами</span><b>{{ t.toNews || 0 }}</b></div>
                <div class="kv"><span>Заблокировано вложений и вирусов</span><b>{{ t.banned || 0 }}</b></div>
                <div class="divider" />
                <div class="group-title">Кто сработал в оценке</div>
                <div class="kv"><span>Bayes (наша обученная база)</span><b>{{ t.bayes || 0 }}</b></div>
                <div class="kv"><span>Чёрные списки ссылок (URIBL, SURBL, DBL)</span><b>{{ t.uribl || 0 }}</b></div>
                <div class="kv"><span>Отпечатки писем Pyzor / Razor</span><b>{{ t.pyzor || 0 }} / {{ t.razor || 0 }}</b></div>
                <div class="divider" />
                <div class="group-title">Отбито на входе, письмо не принималось</div>
                <div class="kv"><span>Серверы из чёрных списков (postscreen)</span><b>{{ t.dnsbl || 0 }}</b></div>
                <div class="kv"><span>Подделка нашего домена без входа</span><b>{{ t.authreq || 0 }}</b></div>
                <div class="kv"><span>Все отказы на этапе приёма</span><b>{{ t.rejects || 0 }}</b></div>
                <p class="hint" style="margin-top: 10px">Вчера: принято {{ (y.clean || 0) + caughtY }}, поймано {{ caughtY }}, отбито {{ (y.dnsbl || 0) + (y.rejects || 0) }}.</p>
            </div>

            <div>
                <div class="card card--pad">
                    <div class="card__title" style="display: flex; align-items: center">Обучение фильтра<span style="flex: 1" /><span class="chip" :class="bayes.active ? 'chip--ok' : 'chip--warn'">{{ bayes.active ? 'работает' : 'мало данных' }}</span></div>
                    <div class="kv"><span>Выучено спама</span><b>{{ bayes.nspam }}</b></div>
                    <div class="kv"><span>Выучено нормальных писем</span><b>{{ bayes.nham }}</b></div>
                    <div class="kv"><span>Признаков в базе</span><b>{{ bayes.ntokens }}</b></div>
                    <div class="kv"><span>Ждут обучения</span><b>спам {{ learning.spool.spam }} · норма {{ learning.spool.ham }}</b></div>
                    <p class="hint">Каждое письмо, которое сотрудник переносит в «Спам» или из него, попадает в очередь; cron учит раз в 5 минут. Bayes участвует в оценке после 200 писем каждого рода.</p>
                    <button class="btn btn--sm" type="button" :disabled="busy || (!learning.spool.spam && !learning.spool.ham)" @click="learnNow"><Icon name="play" :size="14" />Обучить сейчас</button>
                    <div v-if="learning.log.length" class="group-title" style="margin-top: 12px">Журнал обучения</div>
                    <div v-for="(l, i) in learning.log" :key="i" class="kv kv--start"><span class="mono faint" style="white-space: nowrap">{{ l.at.slice(5, 16) }}</span><span :class="{ 'no': l.text.includes('ОШИБКА') }">{{ l.kind === 'spam' ? 'спам' : 'норма' }}: {{ l.text }}</span></div>
                </div>

                <div class="card card--pad" style="margin-top: 16px">
                    <div class="card__title">Пороги оценки</div>
                    <p class="hint">SpamAssassin считает письмо спамом от {{ net.required }} баллов. Amavis помечает тему и кладёт в «Спам» от порога «пометка», а от порога «отбрасывание» отправляет в карантин.</p>
                    <div class="field__row">
                        <label class="field" style="flex: 1"><span class="field__label">Пометка</span><input v-model="levels.tag2" class="input" type="number" step="0.1" min="3" max="15"></label>
                        <label class="field" style="flex: 1"><span class="field__label">Отбрасывание</span><input v-model="levels.kill" class="input" type="number" step="0.1" min="3" max="20"></label>
                    </div>
                    <button class="btn btn--primary btn--sm" type="button" :disabled="busy" @click="saveLevels">Сохранить</button>
                    <span class="hint" style="margin-left: 8px">Amavis перезапустится, письма не теряются.</span>
                </div>
            </div>
        </div>

        <div class="grid-2-1" style="margin-top: 16px">
            <div class="card card--pad">
                <div class="card__title">Внешние базы</div>
                <div v-for="d in net.dnsbl" :key="d.host" class="kv"><span>Чёрный список серверов <span class="mono">{{ d.host }}</span> · вес {{ d.weight }}</span><span class="chip" :class="d.ok ? 'chip--ok' : 'chip--no'">{{ d.ok ? 'отвечает' : 'не отвечает' }}</span></div>
                <p class="hint">Письмо не принимается при сумме весов от {{ net.threshold }}.</p>
                <div class="kv"><span>Razor, отпечатки спам-писем</span><span class="chip" :class="net.razor ? 'chip--ok' : 'chip--no'">{{ net.razor ? 'зарегистрирован' : 'не зарегистрирован' }}</span></div>
                <div class="kv"><span>Pyzor, отпечатки спам-писем</span><span class="chip" :class="net.pyzor ? 'chip--ok' : 'chip--no'">{{ net.pyzor ? 'отвечает' : 'не отвечает' }}</span></div>
                <div class="kv"><span>Серый список (iRedAPD)</span><span class="chip" :class="net.greylist ? 'chip--ok' : 'chip--off'">{{ net.greylist ? 'включён' : 'выключен' }}</span></div>
                <div class="kv"><span>Правила SpamAssassin обновлены</span><b>{{ when(net.rulesUpdated) }}</b></div>
            </div>
            <div class="card card--pad">
                <div class="card__title">Общие правила по отправителям</div>
                <div class="kv"><span>Домены и адреса → «Спам»</span><b>{{ rules.spam }}</b></div>
                <div class="kv"><span>Домены и адреса → «Рассылки»</span><b>{{ rules.lists }}</b></div>
                <div class="kv"><span>Белый список</span><b>{{ rules.ham }}</b></div>
                <div class="kv"><span>Правило становится общим при голосах</span><b>спам {{ rules.spamVotes }} · рассылки {{ rules.listsVotes }}</b></div>
                <div class="kv"><span>Заявки сотрудников ждут решения</span><b :class="{ warn: rules.pending }">{{ rules.pending }}</b></div>
                <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap">
                    <Link class="btn btn--sm" href="/rules">Правила и заявки</Link>
                    <Link class="btn btn--sm" href="/settings/spam">Карантин и белый список</Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
