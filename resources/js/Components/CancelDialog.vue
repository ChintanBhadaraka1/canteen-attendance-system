<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Ban } from '@lucide/vue';
import ConfirmDialog from './ConfirmDialog.vue';
import FormField from './FormField.vue';
import { focusFirstError } from '../forms';

// Cancels a record with a reason: open(url, title, text).
const dialog = ref(null);
const about = ref({ url: '', title: '', text: '' });
const form = useForm({ reason: '' });

function open(url, title, text = '') {
    about.value = { url, title, text };
    form.reset();
    form.clearErrors();
    dialog.value.open();
}
function cancel() {
    if (form.processing) return;
    form.post(about.value.url, { preserveScroll: true, onSuccess: () => dialog.value?.close(), onError: focusFirstError });
}
defineExpose({ open });
</script>

<template>
    <ConfirmDialog ref="dialog" :title="about.title" :text="about.text" confirm-label="Cancel it" cancel-label="Keep it" :icon="Ban" :busy="form.processing" @confirm="cancel">
        <FormField id="reason" v-model="form.reason" label="Why is it cancelled?" maxlength="200" autocomplete="off" required :error="form.errors.reason" @keydown.enter.prevent="cancel" />
    </ConfirmDialog>
</template>
