<script setup>
import Icon from '../../Components/Icon.vue';

defineProps({
    node: Object,
    root: { type: Boolean, default: false },
});

const kindClass = {
    'mailbox': 'chip--ok',
    'mailbox-off': 'chip--off',
    'alias': 'chip--acc',
    'domain-alias': 'chip--acc',
    'list': 'chip--acc',
    'external': 'chip--warn',
    'missing': 'chip--no',
    'loop': 'chip--no',
};
</script>

<template>
    <div class="trace" :style="{ paddingLeft: root ? 0 : '28px' }">
        <div style="display: flex; align-items: center; gap: 10px; padding: 6px 0; font-size: 13.5px; flex-wrap: wrap">
            <Icon v-if="!root" name="chevron" :size="14" style="color: #98A3B3" />
            <span class="mono">{{ node.address }}</span>
            <span class="chip" :class="kindClass[node.kind] || 'chip--off'">{{ node.label }}</span>
            <span v-if="node.note" class="hint">{{ node.note }}</span>
        </div>
        <TraceNode v-for="(child, i) in node.next" :key="i" :node="child" />
    </div>
</template>
