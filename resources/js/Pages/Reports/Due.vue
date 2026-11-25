<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Download, Printer, Search, Wallet } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { money, shortDate } from '../../format';

const props = defineProps({
    rows: { type: Array, required: true },
    filters: { type: Object, required: true },
    colleges: { type: Array, required: true },
    total: { type: Number, required: true },
    deleted: { type: Number, required: true },
});
const search = ref(props.filters.search);
const college = ref(props.filters.college);
const order = ref(`${props.filters.sort}-${props.filters.dir}`);
const asked = computed(() => {
    const [sort, dir] = order.value.split('-');
    return { search: search.value, college: college.value, sort, dir };
});
const show = () => router.get('/reports/due', asked.value, { preserveState: true, preserveScroll: true });
const url = computed(() => `/reports/due/download?${new URLSearchParams(props.filters)}`);
const day = (date) => (date ? shortDate(date) : 'None');
const print = () => window.print();
</script>

<template>
    <AdminLayout title="Money due" :back="{ href: '/reports', label: 'Back to reports' }">
        <template #actions>
            <AppButton variant="plain" :icon="Download" :href="url" external>Download</AppButton>
            <AppButton variant="plain" :icon="Printer" @click="print">Print</AppButton>
        </template>
        <form class="filters" @submit.prevent="show">
            <FormField id="search" v-model="search" label="Find a student" placeholder="Name or canteen ID" maxlength="100" autocomplete="off" />
            <FormField id="college" v-model="college" as="select" label="College">
                <option value="">All</option>
                <option v-for="one in colleges" :key="one">{{ one }}</option>
            </FormField>
            <FormField id="order" v-model="order" as="select" label="Order">
                <option value="name-asc">Name</option>
                <option value="due-desc">Highest due first</option>
                <option value="due-asc">Lowest due first</option>
                <option value="last_payment-asc">Oldest payment first</option>
                <option v-if="!['name-asc', 'due-desc', 'due-asc', 'last_payment-asc'].includes(order)" :value="order">Other</option>
            </FormField>
            <AppButton type="submit" :icon="Search">Show</AppButton>
        </form>
        <section class="panel sum space">
            <div class="tot"><span>{{ rows.length }} {{ rows.length === 1 ? 'student' : 'students' }}</span><span>{{ money(total) }}</span></div>
            <p v-if="deleted">{{ money(deleted) }} of this is from deleted students, so Home shows {{ money(total - deleted) }}.</p>
        </section>
        <p v-if="!rows.length" class="panel">Nobody owes money here.</p>
        <table v-else role="table">
            <thead role="rowgroup"><tr role="row"><th v-for="head in ['Student', 'Due', 'Last payment', 'Last meal']" :key="head" role="columnheader" scope="col">{{ head }}</th><th role="columnheader" scope="col" class="tools"><span class="sr-only">Actions</span></th></tr></thead>
            <tbody role="rowgroup">
                <tr v-for="row in rows" :key="row.id" role="row">
                    <td role="cell"><b>{{ row.name }}</b> <StatusTag v-if="row.tag" kind="due">{{ row.tag }}</StatusTag><br><span class="small">{{ [row.canteen_id ?? 'No canteen ID', row.college].filter(Boolean).join(', ') }}</span></td>
                    <td role="cell" data-l="Due"><b>{{ money(row.due) }}</b></td>
                    <td role="cell" data-l="Last payment">{{ day(row.last_payment) }}</td>
                    <td role="cell" data-l="Last meal">{{ day(row.last_meal) }}</td>
                    <td role="cell" class="tools"><AppButton v-if="row.tag !== 'Deleted'" variant="plain" :icon="Wallet" :href="`/bill/${row.id}/edit`">Open bill<span class="sr-only"> of {{ row.name }}</span></AppButton></td>
                </tr>
            </tbody>
        </table>
    </AdminLayout>
</template>

<style scoped>
.space { margin-bottom: 1.2rem; }
td { overflow-wrap: anywhere; }
</style>
