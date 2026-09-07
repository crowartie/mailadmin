<script setup>
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import MailLayout from '../../Layouts/MailLayout.vue';
import Icon from '../../Components/Icon.vue';

const props = defineProps({
    user: String,
    folders: Array,
    folder: String,
    messages: Array,
    total: Number,
    page: Number,
    pages: Number,
});

const open = ref(null);
const loading = ref(false);

async function read(m) {
    loading.value = true;
    open.value = null;
    try {
        const r = await fetch(`/mail/message/${encodeURIComponent(props.folder)}/${m.uid}`, { headers: { Accept: 'application/json' } });
        open.value = await r.json();
        m.seen = true;
    } finally {
        loading.value = false;
    }
}

function when(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const today = new Date();
    if (d.toDateString() === today.toDateString()) return d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

function size(bytes) {
    if (!bytes) return '';
    return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} МБ` : `${Math.round(bytes / 1024)} КБ`;
}

function goPage(p) {
    router.get(`/mail/folder/${encodeURIComponent(props.folder)}`, { page: p }, { preserveState: false });
}
</script>

<template>
    <MailLayout :user="user">
        <!-- Папки -->
        <nav class="nav" aria-label="Папки">
            <button class="btn btn--primary btn--block" type="button" style="margin-bottom: 12px" title="Отправка — следующий шаг каркаса">
                <Icon name="plus" :size="16" />Написать
            </button>
            <Link
                v-for="f in folders"
                :key="f.path"
                :href="`/mail/folder/${encodeURIComponent(f.path)}`"
                class="nav__item"
                :class="{ 'nav__item--on': f.path === folder }"
            >
                <span>{{ f.name }}</span>
                <span v-if="f.unread" class="nav__count" style="color: #1B4FC4; font-weight: 600">{{ f.unread }}</span>
            </Link>
        </nav>

        <!-- Список -->
        <section class="mail-list">
            <header class="mail-list__head">
                <div class="topbar__search" style="flex: 1">
                    <Icon name="search" :size="18" />
                    <input type="search" placeholder="Поиск по письмам — следующий шаг" disabled>
                </div>
            </header>
            <div class="mail-list__meta faint">{{ folders.find((f) => f.path === folder)?.name || folder }} · {{ total }} писем · страница {{ page }} из {{ pages || 1 }}</div>

            <div class="mail-list__rows">
                <button
                    v-for="m in messages"
                    :key="m.uid"
                    type="button"
                    class="mail-row"
                    :class="{ 'mail-row--unread': !m.seen, 'mail-row--on': open && open.uid === m.uid }"
                    @click="read(m)"
                >
                    <span class="mail-row__dot" />
                    <span class="mail-row__from">{{ m.from.name }}</span>
                    <span class="mail-row__subject">{{ m.subject }}</span>
                    <span class="mail-row__meta">
                        <Icon v-if="m.hasAttachments" name="list" :size="14" />
                        <span>{{ when(m.date) }}</span>
                    </span>
                </button>
                <div v-if="!messages.length" class="empty">В этой папке пусто</div>
            </div>

            <footer v-if="pages > 1" class="mail-list__foot">
                <button class="btn btn--sm" type="button" :disabled="page <= 1" @click="goPage(page - 1)">Новее</button>
                <button class="btn btn--sm" type="button" :disabled="page >= pages" @click="goPage(page + 1)">Старее</button>
            </footer>
        </section>

        <!-- Чтение -->
        <section class="mail-read">
            <div v-if="loading" class="empty">Загружаю письмо…</div>
            <div v-else-if="!open" class="empty" style="padding-top: 120px">
                Выберите письмо слева
                <div class="hint" style="margin-top: 8px">Каркас: чтение работает, ответ и отправка — следующий шаг</div>
            </div>
            <article v-else class="mail-read__body">
                <h1 class="mail-read__subject">{{ open.subject }}</h1>
                <div class="mail-read__from">
                    <div class="avatar">{{ (open.from.name || '?').slice(0, 2).toUpperCase() }}</div>
                    <div style="min-width: 0">
                        <div><b>{{ open.from.name }}</b> <span class="hint mono">{{ open.from.mail }}</span></div>
                        <div class="hint">
                            кому: {{ open.to.map((a) => a.name).join(', ') || '—' }}
                            <template v-if="open.cc.length"> · копия: {{ open.cc.map((a) => a.name).join(', ') }}</template>
                        </div>
                    </div>
                    <span class="faint" style="margin-left: auto; white-space: nowrap">{{ new Date(open.date).toLocaleString('ru-RU') }}</span>
                </div>

                <div v-if="open.attachments.length" class="tags" style="margin: 12px 0">
                    <span v-for="a in open.attachments" :key="a.name" class="chip chip--acc">{{ a.name }} · {{ size(a.size) }}</span>
                </div>

                <div v-if="open.html" class="mail-read__html" v-html="open.html" />
                <pre v-else class="mail-read__text">{{ open.text }}</pre>
            </article>
        </section>
    </MailLayout>
</template>
