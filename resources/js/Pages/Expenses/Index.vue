<script setup>
import { computed, ref } from 'vue';
import { Ban, Download, Plus } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import CancelDialog from '../../Components/CancelDialog.vue';
import DataList from '../../Components/DataList.vue';
import DateRange from '../../Components/DateRange.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { shortDate } from '../../format';
import { rupees } from '../../stock';

const props = defineProps({
    expenses: { type: Object, required: true },
    filters: { type: Object, required: true },
    types: { type: Array, required: true },
    totals: { type: Array, required: true },
    total: { type: Number, required: true },
});
const columns = [
    { key: 'date', label: 'Date', sortable: true },
    { key: 'type_name', label: 'Type' },
    { key: 'amount', label: 'Amount' },
    { key: 'method', label: 'Paid by' },
];
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const url = computed(() => `/expenses/download?${new URLSearchParams(props.filters)}`);
const dialog = ref(null);
const ask = (row) => dialog.value.open(`/expenses/${row.id}/cancel`, 'Cancel this expense?', `${row.type_name} ${rupees(row.amount)} on ${shortDate(row.date)}.`);
</script>

<template>
    <AdminLayout title="Expenses">
        <template #actions>
            <AppButton variant="plain" :icon="Download" :href="url" external>Download</AppButton>
            <AppButton :icon="Plus" href="/expenses/create">Add expense</AppButton>
        </template>
        <DataList :columns="columns" :rows="expenses" :filters="filters" noun="expense" search-label="Find an expense" search-hint="Type, paid to or note">
            <template #filters="{ visit }">
                <DateRange id="expenses" v-model:from="from" v-model:to="to" @update:from="(value) => visit({ from: value })" @update:to="(value) => visit({ to: value })" />
                <FormField id="type" as="select" label="Type" :model-value="filters.type" @update:model-value="(type) => visit({ type })">
                    <option value="">All</option>
                    <option v-for="one in types" :key="one.id" :value="one.id">{{ one.name }}</option>
                </FormField>
                <FormField id="method" as="select" label="Paid by" :model-value="filters.method" @update:model-value="(method) => visit({ method })">
                    <option value="">All</option>
                    <option value="cash">Cash</option>
                    <option value="upi">UPI</option>
                    <option value="bank">Bank</option>
                </FormField>
                <FormField id="show" as="select" label="Show" :model-value="filters.show" @update:model-value="(show) => visit({ show })">
                    <option value="">All</option>
                    <option value="saved">Not cancelled</option>
                    <option value="cancelled">Cancelled</option>
                </FormField>
            </template>
            <template #cell-date="{ row }"><b>{{ shortDate(row.date) }}</b></template>
            <template #cell-type_name="{ row }">
                <b>{{ row.type_name }}</b><br>
                <span class="small">{{ [row.paid_to, row.note, `by ${row.user_name}`].filter(Boolean).join(', ') }}</span>
            </template>
            <template #cell-amount="{ row }">{{ rupees(row.amount) }}<template v-if="row.cancelled"><br><StatusTag kind="due">Cancelled</StatusTag> <span class="small">{{ row.cancel_reason }}</span></template></template>
            <template #actions="{ row }"><AppButton v-if="!row.cancelled" variant="danger" :icon="Ban" @click="ask(row)">Cancel<span class="sr-only"> {{ row.type_name }} of {{ shortDate(row.date) }}</span></AppButton></template>
        </DataList>
        <section v-if="totals.length" class="panel sum totals">
            <h2>Totals of this list</h2>
            <div v-for="one in totals" :key="one.name"><span class="word">{{ one.name }}</span><span>{{ rupees(one.total) }}</span></div>
            <div class="tot"><span>Total</span><span>{{ rupees(total) }}</span></div>
        </section>
        <CancelDialog ref="dialog" />
    </AdminLayout>
</template>

<style scoped>
.totals { margin-top: 1.5rem; }
.word { min-width: 0; overflow-wrap: anywhere; }
</style>
