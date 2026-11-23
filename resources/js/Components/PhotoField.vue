<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { CircleAlert } from '@lucide/vue';

const props = defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    current: { type: String, default: null },
    error: { type: String, default: undefined },
});
const file = defineModel({ type: Object, default: null });

const preview = ref(props.current);
let blob = null;

function choose(event) {
    file.value = event.target.files[0] ?? null;
    if (blob) URL.revokeObjectURL(blob);
    blob = file.value ? URL.createObjectURL(file.value) : null;
    preview.value = blob ?? props.current;
}
onBeforeUnmount(() => blob && URL.revokeObjectURL(blob));
</script>

<template>
    <div class="field photo" :class="{ bad: error }">
        <label :for="id">{{ label }}</label>
        <img v-if="preview" :src="preview" alt="" @error="preview = null">
        <input
            :id="id"
            type="file"
            accept=".jpg,.jpeg,.png,.gif"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? `${id}-error ${id}-help` : `${id}-help`"
            @change="choose"
        >
        <p :id="`${id}-help`" class="help">JPG, PNG or GIF, up to 2 MB.</p>
        <p v-if="error" :id="`${id}-error`" class="err"><CircleAlert aria-hidden="true" /><span>{{ error }}</span></p>
    </div>
</template>

<style scoped>
.photo label { display: block; font-weight: 600; margin-bottom: .3rem; }
.photo img { width: 8rem; height: 8rem; object-fit: cover; border-radius: 10px; margin-bottom: .6rem; }
.photo input { width: 100%; min-height: 3.1rem; padding: .4rem; border: 2px solid var(--color-field); border-radius: 8px; background: #fff; }
.photo input::file-selector-button { min-height: 2.2rem; padding: 0 1rem; margin-right: .8rem; border-radius: 6px; background: var(--color-paper); font-weight: 650; cursor: pointer; }
.photo .help { color: var(--color-muted); margin-top: .2rem; }
.photo .err { color: var(--color-danger); font-weight: 600; display: flex; gap: .4rem; align-items: center; margin-top: .3rem; }
.photo.bad input { border-color: var(--color-danger); }
</style>
