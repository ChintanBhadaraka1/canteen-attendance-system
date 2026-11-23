<script setup>
import { FileText, Wallet } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import Avatar from '../../Components/Avatar.vue';
import DataList from '../../Components/DataList.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { displayName, money } from '../../format';

defineProps({
    bills: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Student', sortable: true },
    { key: 'canteen_id', label: 'Canteen ID', sortable: true },
    { key: 'balance', label: 'Bill', sortable: true },
];
const text = (balance) =>
    balance > 0 ? `${money(balance)} due` : balance < 0 ? `${money(-balance)} advance` : 'Nothing due';
</script>

<template>
    <AdminLayout title="Bills and payments">
        <DataList
            :columns="columns" :rows="bills" :filters="filters" noun="bill"
            search-label="Find a student" search-hint="Name or canteen ID"
        >
            <template #filters="{ visit }">
                <FormField id="show" as="select" label="Show" :model-value="filters.show" @update:model-value="(show) => visit({ show })">
                    <option value="">All bills</option>
                    <option value="due">Money due</option>
                    <option value="advance">Advance</option>
                    <option value="clear">Nothing due</option>
                </FormField>
            </template>
            <template #cell-name="{ row }">
                <div class="who"><Avatar :name="row.name" :photo="row.profile_pic" /><b>{{ displayName(row) }}</b></div>
            </template>
            <template #cell-balance="{ row }">
                <StatusTag :kind="row.balance > 0 ? 'due' : 'ok'">{{ text(row.balance) }}</StatusTag>
            </template>
            <template #actions="{ row }">
                <AppButton v-if="row.balance > 0" :icon="Wallet" :href="`/bill/${row.student_id}/edit`">Take payment<span class="sr-only"> from {{ displayName(row) }}</span></AppButton>
                <AppButton v-else variant="plain" :icon="FileText" :href="`/bill/${row.student_id}/edit`">Receipts<span class="sr-only"> of {{ displayName(row) }}</span></AppButton>
            </template>
        </DataList>
    </AdminLayout>
</template>
