<script setup>
import { Banknote, Landmark, Smartphone } from '@lucide/vue';

defineProps({ error: { type: String, default: undefined } });
const model = defineModel({ type: String });
const methods = [{ key: 'cash', label: 'Cash', icon: Banknote }, { key: 'upi', label: 'UPI', icon: Smartphone }, { key: 'bank', label: 'Bank', icon: Landmark }];
</script>

<template>
    <div>
        <fieldset class="seg three">
            <legend>How was it paid?</legend>
            <button v-for="one in methods" :key="one.key" type="button" :aria-pressed="model === one.key" :aria-invalid="error ? 'true' : undefined" @click="model = one.key"><component :is="one.icon" aria-hidden="true" />{{ one.label }}</button>
        </fieldset>
        <p v-if="error" class="err" role="alert">{{ error }}</p>
    </div>
</template>

<style scoped>
.three { grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr)); min-width: 0; }
</style>
