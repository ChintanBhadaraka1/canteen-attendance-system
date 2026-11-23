<script setup>
import { computed } from 'vue';
import { CircleAlert } from '@lucide/vue';

defineOptions({ inheritAttrs: false });
const props = defineProps({
    label: { type: String, required: true },
    id: { type: String, required: true },
    error: { type: String, default: undefined },
    help: { type: String, default: undefined },
    as: { type: String, default: 'input' },
});
const model = defineModel({ type: [String, Number] });

const describedBy = computed(() =>
    [props.error && `${props.id}-error`, props.help && `${props.id}-help`].filter(Boolean).join(' ') || undefined);
</script>

<template>
    <div class="field" :class="{ bad: error }">
        <label :for="id">{{ label }}</label>
        <select
            v-if="as === 'select'"
            :id="id"
            v-model="model"
            v-bind="$attrs"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
        >
            <slot />
        </select>
        <input
            v-else
            :id="id"
            v-model="model"
            v-bind="$attrs"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
        >
        <p v-if="help" :id="`${id}-help`" class="help">{{ help }}</p>
        <p v-if="error" :id="`${id}-error`" class="err"><CircleAlert class="alert" aria-hidden="true" /><span>{{ error }}</span></p>
    </div>
</template>

<style scoped>
.field { min-width: 0; }
.field label { display: block; font-weight: 600; margin-bottom: 5px; }
.field input, .field select {
    width: 100%;
    padding: 11px 13px;
    border: 1.5px solid #7788a8;
    border-radius: 6px;
    color: var(--color-ink);
    background: #fff;
}
.field input:focus, .field select:focus { border-color: var(--color-primary); outline: 3px solid #1d4ed840; outline-offset: 0; }
.field .help { color: var(--color-muted); margin-top: 5px; }
.field .err { color: var(--color-danger); font-size: .97rem; margin-top: 5px; display: flex; gap: 6px; align-items: center; }
.field .alert { width: 1.25em; height: 1.25em; flex: none; }
.field.bad input, .field.bad select { border-color: var(--color-danger); }
</style>
