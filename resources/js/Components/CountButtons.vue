<script setup>
import { Minus, Plus } from '@lucide/vue';
import AppButton from './AppButton.vue';

const props = defineProps({
    name: { type: String, required: true },
    min: { type: Number, default: 0 },
    max: { type: Number, default: 99 },
});
const count = defineModel({ type: Number, required: true });
const change = (by) => { count.value = Math.min(props.max, Math.max(props.min, count.value + by)); };
</script>

<template>
    <span class="count">
        <AppButton variant="plain" :icon="Minus" :disabled="count <= min" @click="change(-1)">Less<span class="sr-only"> {{ name }}</span></AppButton>
        <output aria-live="polite">{{ count }}</output>
        <AppButton variant="plain" :icon="Plus" :disabled="count >= max" @click="change(1)">More<span class="sr-only"> {{ name }}</span></AppButton>
    </span>
</template>

<style scoped>
.count { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem; }
.count output { min-width: 2rem; text-align: center; font-weight: 700; font-size: 1.25rem; }
</style>
