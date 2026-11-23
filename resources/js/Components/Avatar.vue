<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    name: { type: String, required: true },
    photo: { type: String, default: null },
});
const broken = ref(false);
watch(() => props.photo, () => { broken.value = false; });
</script>

<template>
    <img v-if="photo && !broken" class="ava" :src="`/image/profile/${encodeURIComponent(photo)}`" alt="" @error="broken = true">
    <span v-else class="ava" aria-hidden="true">{{ name.trim().charAt(0).toUpperCase() }}</span>
</template>
