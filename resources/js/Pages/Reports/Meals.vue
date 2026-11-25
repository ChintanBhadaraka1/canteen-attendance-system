<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Download, Printer, Search } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import DateRange from '../../Components/DateRange.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { money, shortDate } from '../../format';
import { amountOf, rupees } from '../../stock';

const props = defineProps({
    filters: { type: Object, required: true },
    message: { type: String, default: null },
    days: { type: Array, required: true },
    meals: { type: Array, required: true },
    colleges: { type: Array, required: true },
    totals: { type: Object, required: true },
    extras: { type: Array, required: true },
    waste: { type: Array, required: true },
});
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const show = () => router.get('/reports/meals', { from: from.value, to: to.value }, { preserveState: true });
const url = computed(() => `/reports/meals/download?${new URLSearchParams(props.filters)}`);
const tables = computed(() => [
    { title: 'By meal', head: 'Meal', rows: props.meals },
    { title: 'By college', head: 'College', rows: props.colleges },
    { title: 'By day', head: 'Day', rows: props.days.map((row) => ({ ...row, name: shortDate(row.name) })) },
]);
const print = () => window.print();
</script>

<template>
    <AdminLayout title="Meals and waste" :back="{ href: '/reports', label: 'Back to reports' }">
        <template #actions>
            <AppButton variant="plain" :icon="Download" :href="url" external>Download</AppButton>
            <AppButton variant="plain" :icon="Printer" @click="print">Print</AppButton>
        </template>
        <form class="filters" @submit.prevent="show">
            <DateRange id="meals" v-model:from="from" v-model:to="to" />
            <AppButton type="submit" :icon="Search">Show</AppButton>
        </form>
        <p v-if="message" class="panel" role="alert">{{ message }}</p>
        <section class="panel sum">
            <h2>{{ shortDate(filters.from) }} to {{ shortDate(filters.to) }}</h2>
            <div><span>Students</span><span>{{ totals.own }}</span></div>
            <div><span>Guests on cards</span><span>{{ totals.card }}</span></div>
            <div><span>Walk-ins</span><span>{{ totals.walk_in }}</span></div>
            <div class="tot"><span>Meals</span><span>{{ totals.own + totals.card + totals.walk_in }}</span></div>
        </section>
        <section v-for="table in tables" :key="table.title" class="space">
            <h2>{{ table.title }}</h2>
            <p v-if="!table.rows.length">No meals.</p>
            <table v-else role="table">
                <thead role="rowgroup"><tr role="row"><th v-for="head in [table.head, 'Students', 'Guests on cards', 'Walk-ins']" :key="head" role="columnheader" scope="col">{{ head }}</th></tr></thead>
                <tbody role="rowgroup">
                    <tr v-for="row in table.rows" :key="row.name" role="row">
                        <td role="cell"><b>{{ row.name }}</b></td>
                        <td role="cell" data-l="Students">{{ row.own }}</td>
                        <td role="cell" data-l="Guests on cards">{{ row.card }}</td>
                        <td role="cell" data-l="Walk-ins">{{ row.walk_in }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
        <div class="two space">
            <section class="panel sum">
                <h2>Extras</h2>
                <p v-if="!extras.length">None.</p>
                <div v-for="row in extras" :key="row.name"><span class="word">{{ row.name }} × {{ row.quantity }}</span><span>{{ money(row.amount) }}</span></div>
            </section>
            <section class="panel sum">
                <h2>Waste</h2>
                <p v-if="!waste.length">None.</p>
                <div v-for="(row, index) in waste" :key="index"><span class="word">{{ row.item }}, {{ amountOf(row.quantity, row.unit) }}<br><span class="small">{{ row.reason }}</span></span><span>{{ rupees(row.cost) }}</span></div>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
.space { margin-top: 1.5rem; }
.space > h2 { margin-bottom: .6rem; }
.word, td { min-width: 0; overflow-wrap: anywhere; }
</style>
