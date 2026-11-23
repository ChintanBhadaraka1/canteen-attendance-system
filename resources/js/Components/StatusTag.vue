<script setup>
import { Check, Clock } from '@lucide/vue';

defineProps({ kind: { type: String, required: true } });
</script>

<template>
    <span class="tag" :class="kind">
        <component :is="kind === 'due' ? Clock : Check" aria-hidden="true" /><slot />
    </span>
</template>
