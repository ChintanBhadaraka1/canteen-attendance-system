<script setup>
import { ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import AppButton from './AppButton.vue';
import FormField from './FormField.vue';
import { lastMonths, monthName } from '../format';

defineProps({ student: { type: Number, required: true } });
const months = lastMonths(usePage().props.today, 24);
const month = ref(months[0]);
</script>

<template>
    <section class="panel">
        <h2>Monthly bill</h2>
        <div class="filters">
            <FormField id="bill-month" v-model="month" as="select" label="Month">
                <option v-for="one in months" :key="one" :value="one">{{ monthName(one) }}</option>
            </FormField>
            <AppButton variant="plain" :icon="Download" :href="`/bill/statement/${student}?month=${month}`" external>Download bill</AppButton>
        </div>
    </section>
</template>
