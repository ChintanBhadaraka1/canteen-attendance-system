<script setup>
defineProps({
    name: { type: String, default: '' },
    college: { type: String, default: '' },
    canteenId: { type: String, default: '' },
});
</script>

<template>
    <div class="card">
        <div class="nm">{{ name || 'Your name' }}</div>
        <div class="cl">{{ college || 'Your college' }}</div>
        <div class="id"><slot>Canteen ID: {{ canteenId }}</slot></div>
    </div>
</template>

<style scoped>
.card {
    margin-top: auto;
    background: var(--color-paper);
    color: var(--color-ink);
    border-radius: 8px;
    padding: 22px;
    min-height: 190px;
    display: flex;
    flex-direction: column;
}
.nm { font-weight: 750; font-stretch: 80%; font-size: 1.75rem; line-height: 1.1; overflow-wrap: anywhere; }
.cl { color: var(--color-muted); overflow-wrap: anywhere; }
.id { margin-top: auto; border-top: 2px dashed #56719f; padding-top: 12px; overflow-wrap: anywhere; }
</style>
