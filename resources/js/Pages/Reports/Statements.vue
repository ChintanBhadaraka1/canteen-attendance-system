<script setup>
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { lastMonths, money, monthName } from '../../format';

const props = defineProps({
    filters: { type: Object, required: true },
    message: { type: String, default: null },
    colleges: { type: Array, required: true },
    bills: { type: Array, required: true },
    unprintable: { type: Array, required: true },
    most: { type: Number, required: true },
});
const choices = lastMonths(usePage().props.today, 24);
const list = computed(() => (choices.includes(props.filters.month) ? choices : [props.filters.month, ...choices]));
const query = (change = {}) => {
    const { month, college, owing } = { ...props.filters, ...change };
    return { month, college, owing: owing ? '1' : '' };
};
const visit = (change) => router.get('/reports/statements', query(change), { preserveState: true, preserveScroll: true });
const url = computed(() => `/reports/statements/download?${new URLSearchParams(query())}`);
</script>

<template>
    <AdminLayout title="Monthly bills" :back="{ href: '/reports', label: 'Back to reports' }">
        <p v-if="message" class="panel space" role="alert">{{ message }}</p>
        <div class="filters">
            <FormField id="month" as="select" label="Month" :model-value="filters.month" @update:model-value="(month) => visit({ month })">
                <option v-for="one in list" :key="one" :value="one">{{ monthName(one) }}</option>
            </FormField>
            <FormField id="college" as="select" label="College" :model-value="filters.college" @update:model-value="(college) => visit({ college })">
                <option value="">All</option>
                <option v-for="one in colleges" :key="one">{{ one }}</option>
            </FormField>
            <label class="check"><input type="checkbox" :checked="filters.owing" @change="visit({ owing: $event.target.checked })">Only students who owe money</label>
        </div>
        <section class="panel space">
            <h2>{{ bills.length }} {{ bills.length === 1 ? 'bill' : 'bills' }}</h2>
            <p v-if="!bills.length">No bills for this choice.</p>
            <p v-else-if="bills.length > most">{{ bills.length }} bills are too many for one file. Choose a college.</p>
            <template v-else>
                <p>300 bills take about 15 seconds. Wait until the file opens.</p>
                <AppButton :icon="Download" :href="url" external>Download all in one file</AppButton>
            </template>
            <template v-if="unprintable.length">
                <p><b>These names have letters the bill cannot print. The bill shows the canteen ID instead:</b></p>
                <p v-for="(one, index) in unprintable" :key="index" class="word">{{ one.name }}, {{ one.canteen_id ?? 'no canteen ID' }}</p>
            </template>
        </section>
        <table v-if="bills.length" role="table">
            <thead role="rowgroup"><tr role="row"><th v-for="head in ['Student', 'At month end']" :key="head" role="columnheader" scope="col">{{ head }}</th><th role="columnheader" scope="col" class="tools"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody role="rowgroup">
                <tr v-for="bill in bills" :key="bill.id" role="row">
                    <td role="cell" class="word"><b>{{ bill.name }}</b><br><span class="small">{{ [bill.canteen_id ?? 'No canteen ID', bill.college].filter(Boolean).join(', ') }}</span></td>
                    <td role="cell" data-l="At month end">
                        <StatusTag v-if="!bill.available" kind="due">Not available</StatusTag>
                        <StatusTag v-else :kind="bill.closing > 0 ? 'due' : 'ok'">{{ bill.closing > 0 ? `${money(bill.closing)} to pay` : bill.closing < 0 ? `${money(-bill.closing)} advance` : 'Paid' }}</StatusTag>
                    </td>
                    <td role="cell" class="tools"><AppButton variant="plain" :icon="Download" :href="`/bill/statement/${bill.id}?month=${filters.month}`" external>Bill<span class="sr-only"> of {{ bill.name }}</span></AppButton></td>
                </tr>
            </tbody>
        </table>
    </AdminLayout>
</template>

<style scoped>
.space { margin-bottom: 1.2rem; }
.space p { margin: .5rem 0; }
.word { min-width: 0; overflow-wrap: anywhere; }
</style>
