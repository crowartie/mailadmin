<script setup>
// Проверить адрес: куда придёт письмо и что с ним сделают по дороге.
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import { http } from '../../admin/http';

const props = defineProps({ address: String, result: Object });
const address = ref(props.address || '');
const result = ref(props.result);
const busy = ref(false);
const error = ref('');

const KIND = { ok: 'chip--ok', warn: 'chip--warn', no: 'chip--no', off: 'chip--off' };
async function run() {
    const a = address.value.trim();
    if (!a) return;
    busy.value = true; error.value = '';
    try {
        result.value = await http('GET', '/trace/json?address=' + encodeURIComponent(a));
        router.replace({ url: '/trace?address=' + encodeURIComponent(a), preserveState: true, preserveScroll: true });
    } catch (e) { error.value = e.message; } finally { busy.value = false; }
}
</script>

<template>
    <AppLayout title="Проверить адрес" count="куда придёт письмо и что с ним сделают">
        <div class="card card--pad" style="margin-bottom: 16px">
            <form class="field__row" @submit.prevent="run">
                <input v-model="address" class="input" style="flex: 1; max-width: 480px" type="text" placeholder="info@innotec.su или чужой адрес" autofocus>
                <button class="btn btn--primary" type="submit" :disabled="busy || !address.trim()"><Icon name="search" :size="16" />Проверить</button>
            </form>
            <p class="hint">Для нашего адреса: ящик, псевдонимы, пересылки, правила, общий доступ. Для чужого: куда уйдут наши письма и что сделает антиспам с письмами от него.</p>
            <p v-if="error" class="error">{{ error }}</p>
        </div>

        <div v-if="result" class="card card--flush">
            <div class="card__title" style="padding: 14px 18px 6px">{{ result.address }} <span class="chip" :class="result.local ? 'chip--acc' : 'chip--off'" style="margin-left: 8px">{{ result.local ? 'наш адрес' : 'внешний адрес' }}</span></div>
            <div v-for="(s, i) in result.steps" :key="i" class="trow">
                <span class="chip" :class="KIND[s.kind] || 'chip--off'" style="width: 100px; justify-content: center">{{ { ok: 'ок', warn: 'внимание', no: 'проблема', off: 'справка' }[s.kind] }}</span>
                <span class="trow__body"><span class="trow__title">{{ s.title }}</span><span class="trow__text">{{ s.text }}</span></span>
                <Link v-if="s.href" class="btn btn--sm" :href="s.href">Открыть</Link>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.trow { display: grid; grid-template-columns: 112px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 10px 18px; border-top: 1px solid var(--border); }
.trow__body { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.trow__title { font-weight: 600; }
.trow__text { color: var(--muted); word-break: break-word; }
</style>
