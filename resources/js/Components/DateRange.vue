<script setup>
import FormField from './FormField.vue';

defineProps({ id: { type: String, required: true } });
const from = defineModel('from', { type: String });
const to = defineModel('to', { type: String });
</script>

<template>
    <FormField :id="`${id}-from`" v-model="from" label="From" type="date" />
    <FormField :id="`${id}-to`" v-model="to" label="To" type="date" />
</template>
