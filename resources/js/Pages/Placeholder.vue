<script setup>
import AppLayout from '../Layouts/AppLayout.vue';

defineProps({
    title: String,
    note: String,
});
</script>

<template>
    <AppLayout :title="title">
        <div class="card card--pad" style="max-width: 640px">
            <div class="card__title">Раздел в работе</div>
            <p class="hint" style="margin: 0; line-height: 1.5">{{ note }}</p>
        </div>
    </AppLayout>
</template>
