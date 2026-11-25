<script setup>
import { ClipboardList, Flame, Pencil, Plus, Printer, Trash2 } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import DataList from '../../Components/DataList.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { amountOf, rupees } from '../../stock';

defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, required: true },
    categories: { type: Array, required: true },
    low: { type: Number, required: true },
});
const columns = [
    { key: 'name', label: 'Item', sortable: true },
    { key: 'on_hand', label: 'In stock', sortable: true },
    { key: 'value', label: 'Value', sortable: true },
    { key: 'last', label: 'Last bought at' },
];
</script>

<template>
    <AdminLayout title="Stock">
        <template #actions><AppButton :icon="Plus" href="/stock/create">Add items</AppButton></template>
        <div class="acts start bar-space">
            <AppButton :icon="Flame" href="/stock/use">Used</AppButton>
            <AppButton variant="plain" :icon="Trash2" href="/stock/waste">Wasted</AppButton>
            <AppButton variant="plain" :icon="ClipboardList" href="/stock/count">Stock count</AppButton>
            <AppButton variant="plain" :icon="Printer" href="/stock/shopping-list">Print shopping list<template v-if="low">{{ ` (${low})` }}</template></AppButton>
        </div>
        <DataList :columns="columns" :rows="items" :filters="filters" noun="item" search-label="Find an item" search-hint="Name">
            <template #filters="{ visit }">
                <FormField id="category" as="select" label="Category" :model-value="filters.category" @update:model-value="(category) => visit({ category })">
                    <option value="">All</option>
                    <option v-for="one in categories" :key="one.id" :value="one.id">{{ one.name }}</option>
                </FormField>
                <FormField id="show" as="select" label="Show" :model-value="filters.show" @update:model-value="(show) => visit({ show })">
                    <option value="">All</option>
                    <option value="low">Low</option>
                    <option value="off">Off</option>
                </FormField>
            </template>
            <template #cell-name="{ row }"><b>{{ row.name }}</b><template v-if="row.category"><br><span class="small">{{ row.category }}</span></template></template>
            <template #cell-on_hand="{ row }">{{ amountOf(row.on_hand, row.unit) }} <StatusTag v-if="row.low" kind="due">Low</StatusTag></template>
            <template #cell-value="{ row }">{{ rupees(row.value) }}</template>
            <template #cell-last="{ row }">{{ row.last === null ? 'Not bought yet' : `${rupees(row.last)} per ${row.unit}` }}</template>
            <template #actions="{ row }"><AppButton variant="plain" :icon="Pencil" :href="`/stock/${row.id}`">Open<span class="sr-only"> {{ row.name }}</span></AppButton></template>
        </DataList>
    </AdminLayout>
</template>

<style scoped>
.bar-space { margin-bottom: 1.2rem; }
</style>
