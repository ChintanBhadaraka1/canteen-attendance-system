<script setup>
import { ref, useId } from 'vue';
import AppButton from './AppButton.vue';

defineProps({
    title: { type: String, required: true },
    text: { type: String, default: '' },
    confirmLabel: { type: String, required: true },
    cancelLabel: { type: String, required: true },
    icon: { type: [Object, Function], default: undefined },
    variant: { type: String, default: 'danger solid' },
    busy: Boolean,
});
defineEmits(['confirm', 'closed']);

const dialog = ref(null);
const id = useId();
defineExpose({ open: () => dialog.value.showModal(), close: () => dialog.value.close() });
</script>

<template>
    <dialog ref="dialog" :aria-labelledby="id" @close="$emit('closed')">
        <h2 :id="id">{{ title }}</h2>
        <p>{{ text }}</p>
        <slot />
        <div class="acts">
            <AppButton variant="plain" autofocus @click="dialog.close()">{{ cancelLabel }}</AppButton>
            <AppButton :variant="variant" :icon="icon" :disabled="busy" @click="$emit('confirm')">{{ confirmLabel }}</AppButton>
        </div>
    </dialog>
</template>
