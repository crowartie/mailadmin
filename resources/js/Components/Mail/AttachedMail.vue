<script setup>
// Письмо, приложенное к письму (.eml), — открывается прямо в почте: шапка, тело
// и свои вложения. Так пересылают переписку целиком (обращение №39): скачивать файл
// и открывать его в другой программе больше не нужно.
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import Icon from '../Icon.vue';
import AttachmentViewer from './AttachmentViewer.vue';
import { api } from '../../mail/api';
import { addrList, size, when } from '../../mail/format';
import { isEmpty, isImg, isOffice, isPdf } from '../../mail/attachments';

const props = defineProps({
    // { folder, uid, index, name } — где лежит вложение-письмо
    source: { type: Object, required: true },
});
const emit = defineEmits(['close']);

const mail = ref(null);
const error = ref('');
const viewer = ref(null);

async function load() {
    error.value = '';
    mail.value = null;
    try {
        mail.value = await api.attachedMessage(props.source.folder, props.source.uid, props.source.index);
    } catch (e) {
        error.value = e.message || 'Не удалось прочитать вложенное письмо';
    }
}

const shown = () => (mail.value?.attachments || []).filter((a) => !a.inline);
const canView = (a) => !isEmpty(a) && (isImg(a) || isPdf(a));
const partUrl = (a, inline = false) => api.attachedPartUrl(props.source.folder, props.source.uid, props.source.index, a.index, inline);

function openPart(a) {
    const list = shown().filter(canView);
    viewer.value = {
        start: Math.max(0, list.findIndex((x) => x.index === a.index)),
        items: list.map((x) => ({
            url: partUrl(x, true), downloadUrl: partUrl(x),
            name: x.name, type: isImg(x) ? x.type : 'application/pdf', size: x.size, converted: false,
        })),
    };
}

function onKey(e) {
    // Пока открыт просмотрщик картинки, Escape закрывает его, а не письмо.
    if (e.key === 'Escape' && !viewer.value) { e.stopPropagation(); emit('close'); }
}
onMounted(() => { document.addEventListener('keydown', onKey, true); load(); });
onBeforeUnmount(() => document.removeEventListener('keydown', onKey, true));
watch(() => [props.source.folder, props.source.uid, props.source.index].join('#'), load);
</script>

<template>
    <div class="overlay" @mousedown.self="$emit('close')">
        <div class="dialog dialog--eml" role="dialog" aria-modal="true" aria-labelledby="eml-title">
            <div class="eml__bar">
                <Icon name="mail" :size="16" />
                <h2 id="eml-title">Письмо во вложении</h2>
                <span style="flex: 1" />
                <a class="btn" :href="api.attachmentUrl(source.folder, source.uid, source.index)" :download="source.name || 'письмо.eml'"><Icon name="download" :size="15" />Скачать</a>
                <button class="ib" type="button" title="Закрыть" aria-label="Закрыть" @click="$emit('close')">✕</button>
            </div>

            <div class="eml__body">
                <p v-if="error" class="error" style="margin: 0 0 10px">
                    {{ error }}
                    <button class="btn btn--sm" type="button" style="margin-left: 8px" @click="load">Повторить</button>
                </p>
                <p v-else-if="!mail" class="hint" style="margin: 0">Читаю письмо…</p>

                <template v-else>
                    <h3 class="eml__subject">{{ mail.subject }}</h3>
                    <div class="eml__head">
                        <div><span class="eml__label">От кого</span><b>{{ mail.from?.name || mail.from?.mail || 'неизвестно' }}</b><span v-if="mail.from?.mail && mail.from?.name" class="mono"> &lt;{{ mail.from.mail }}&gt;</span></div>
                        <div v-if="mail.to?.length"><span class="eml__label">Кому</span>{{ addrList(mail.to) }}</div>
                        <div v-if="mail.cc?.length"><span class="eml__label">Копия</span>{{ addrList(mail.cc) }}</div>
                        <div v-if="mail.date"><span class="eml__label">Когда</span>{{ when(mail.date, true) }}</div>
                    </div>

                    <div v-if="shown().length" class="msg__atts" style="padding: 0; margin: 12px 0">
                        <span v-for="a in shown()" :key="a.index" class="att" :class="{ 'att--view': canView(a), 'att--empty': isEmpty(a) }" :title="a.name + ' · ' + a.type">
                            <span v-if="isEmpty(a)" class="att__main"><Icon name="warn" :size="13" /><span class="name">{{ a.name }}</span><span class="sz">файл не дошёл</span></span>
                            <a v-else class="att__main" :href="partUrl(a)" @click="canView(a) && (openPart(a), $event.preventDefault())">
                                <Icon name="clip" :size="13" /><span class="name">{{ a.name }}</span><span class="sz">{{ size(a.size) }}</span>
                            </a>
                            <button v-if="canView(a)" class="att__btn" type="button" title="Посмотреть" aria-label="Посмотреть" @click="openPart(a)"><Icon name="eye" :size="14" /></button>
                            <a v-if="!isEmpty(a)" class="att__btn" :href="partUrl(a)" title="Скачать" aria-label="Скачать"><Icon name="download" :size="14" /></a>
                        </span>
                        <!-- Офисные документы внутри вложенного письма показать не можем: они лежат
                             внутри .eml, преобразование к ним не подключено. Честнее предложить скачать. -->
                        <span v-if="shown().some(isOffice)" class="hint" style="flex-basis: 100%">Документы Word и Excel из вложенного письма открываются после скачивания.</span>
                    </div>

                    <div v-if="mail.html" class="msg__body"><div class="msg__body-inner" v-html="mail.html" /></div>
                    <pre v-else-if="mail.text" class="eml__text">{{ mail.text }}</pre>
                    <p v-else class="hint">В письме нет текста.</p>
                </template>
            </div>
        </div>
        <AttachmentViewer v-if="viewer" :items="viewer.items" :start="viewer.start" @close="viewer = null" />
    </div>
</template>
