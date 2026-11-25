<script setup>
import { ref } from 'vue';
import DataList from '../../Components/DataList.vue';
import DateRange from '../../Components/DateRange.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';

const props = defineProps({
    changes: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const columns = [
    { key: 'when', label: 'When' },
    { key: 'user_name', label: 'Who' },
    { key: 'summary', label: 'What changed' },
];
const types = { attendance: 'Attendance', payment: 'Payments', student: 'Students', meal: 'Meal prices', guest: 'Guests', menu: 'Menu', stock: 'Stock', expense: 'Expenses', staff: 'Staff', cash: 'Cash check', settings: 'Settings' };
const from = ref(props.filters.from);
const to = ref(props.filters.to);
</script>

<template>
    <AdminLayout title="Changes">
        <DataList
            :columns="columns" :rows="changes" :filters="filters" noun="change"
            search-label="Find a change" search-hint="Name, amount or meal"
        >
            <template #filters="{ visit }">
                <FormField id="type" :model-value="filters.type" as="select" label="Show" @update:model-value="(type) => visit({ type })">
                    <option value="">Everything</option>
                    <option v-for="(label, key) in types" :key="key" :value="key">{{ label }}</option>
                </FormField>
                <DateRange id="changes" v-model:from="from" v-model:to="to" @update:from="(value) => visit({ from: value })" @update:to="(value) => visit({ to: value })" />
            </template>
        </DataList>
    </AdminLayout>
</template>
