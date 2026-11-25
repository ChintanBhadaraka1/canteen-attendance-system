<script setup>
import { ref } from 'vue';
import { BarChart3, Eye, Plus } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import DataList from '../../Components/DataList.vue';
import DateRange from '../../Components/DateRange.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { shortDate } from '../../format';
import { rupees } from '../../stock';

const props = defineProps({
    purchases: { type: Object, required: true },
    filters: { type: Object, required: true },
    shops: { type: Array, required: true },
    items: { type: Array, required: true },
});
const columns = [
    { key: 'date', label: 'Date', sortable: true },
    { key: 'shop_name', label: 'Shop' },
    { key: 'total', label: 'Total' },
    { key: 'method', label: 'Paid by' },
];
const from = ref(props.filters.from);
const to = ref(props.filters.to);
</script>

<template>
    <AdminLayout title="Purchases">
        <template #actions>
            <AppButton variant="plain" :icon="BarChart3" href="/purchases/report">Report</AppButton>
            <AppButton :icon="Plus" href="/purchases/create">New purchase</AppButton>
        </template>
        <DataList :columns="columns" :rows="purchases" :filters="filters" noun="purchase" search-label="Find a purchase" search-hint="Shop, bill number or note">
            <template #filters="{ visit }">
                <DateRange id="purchases" v-model:from="from" v-model:to="to" @update:from="(value) => visit({ from: value })" @update:to="(value) => visit({ to: value })" />
                <FormField id="shop" as="select" label="Shop" :model-value="filters.shop" @update:model-value="(shop) => visit({ shop })">
                    <option value="">All</option>
                    <option v-for="one in shops" :key="one.id" :value="one.id">{{ one.name }}</option>
                </FormField>
                <FormField id="item" as="select" label="Item" :model-value="filters.item" @update:model-value="(item) => visit({ item })">
                    <option value="">All</option>
                    <option v-for="one in items" :key="one.id" :value="one.id">{{ one.name }}</option>
                </FormField>
                <FormField id="show" as="select" label="Show" :model-value="filters.show" @update:model-value="(show) => visit({ show })">
                    <option value="">All</option>
                    <option value="saved">Not cancelled</option>
                    <option value="cancelled">Cancelled</option>
                </FormField>
            </template>
            <template #cell-date="{ row }"><b>{{ shortDate(row.date) }}</b></template>
            <template #cell-shop_name="{ row }">{{ row.shop_name ?? 'No shop' }}<br><span class="small">{{ row.count }} {{ row.count === 1 ? 'row' : 'rows' }}<template v-if="row.bill_number">{{ `, bill ${row.bill_number}` }}</template></span></template>
            <template #cell-total="{ row }">{{ rupees(row.total) }} <StatusTag v-if="row.cancelled" kind="due">Cancelled</StatusTag></template>
            <template #actions="{ row }"><AppButton variant="plain" :icon="Eye" :href="`/purchases/${row.id}`">Open<span class="sr-only"> purchase of {{ shortDate(row.date) }}, {{ rupees(row.total) }}</span></AppButton></template>
        </DataList>
    </AdminLayout>
</template>
