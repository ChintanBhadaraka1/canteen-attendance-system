<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import DateRange from '../../Components/DateRange.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { shortDate } from '../../format';
import { amountOf, rupees } from '../../stock';

const props = defineProps({
    filters: { type: Object, required: true },
    message: { type: String, default: null },
    periods: { type: Array, required: true },
    shops: { type: Array, required: true },
    items: { type: Array, required: true },
    total: { type: Number, required: true },
    discount: { type: Number, required: true },
    charges: { type: Number, required: true },
});
const from = ref(props.filters.from);
const to = ref(props.filters.to);
const by = ref(props.filters.by);
watch(() => props.filters, (filters) => { [from.value, to.value, by.value] = [filters.from, filters.to, filters.by]; });
const show = () => router.get('/purchases/report', { from: from.value, to: to.value, by: by.value }, { preserveState: true, preserveScroll: true });
const url = computed(() => `/purchases/report/download?${new URLSearchParams(props.filters)}`);
const print = () => window.print();
const part = (item, kind) => (item[kind] ? `${amountOf(item[kind], item.unit)}, ${rupees(item[`${kind}_cost`])}` : 'None');
</script>

<template>
    <AdminLayout title="Purchase report" :back="{ href: '/purchases', label: 'Back to purchases' }">
        <template #actions>
            <AppButton variant="plain" :icon="Download" :href="url" external>Download</AppButton>
            <AppButton variant="plain" :icon="Printer" @click="print">Print</AppButton>
        </template>
        <form class="filters" @submit.prevent="show">
            <DateRange id="report" v-model:from="from" v-model:to="to" />
            <FormField id="by" v-model="by" as="select" label="Group by">
                <option value="week">Week</option>
                <option value="month">Month</option>
            </FormField>
            <AppButton type="submit" :disabled="!from || !to">Show</AppButton>
        </form>
        <p v-if="message" class="err" role="alert">{{ message }}</p>
        <p>{{ shortDate(filters.from) }} to {{ shortDate(filters.to) }}</p>
        <div class="stack">
            <section class="panel sum">
                <h2>Bought by {{ filters.by }}</h2>
                <p v-if="!periods.length">No purchases in these dates.</p>
                <div v-for="row in periods" :key="row.label"><span>{{ row.label }} <span class="small">({{ row.count }})</span></span><span>{{ rupees(row.total) }}</span></div>
                <div class="tot"><span>Total</span><span>{{ rupees(total) }}</span></div>
            </section>
            <section v-if="shops.length" class="panel sum">
                <h2>By shop</h2>
                <div v-for="(row, index) in shops" :key="index"><span class="word">{{ row.name }}</span><span>{{ rupees(row.total) }}</span></div>
            </section>
            <section v-if="items.length">
                <h2>By item</h2>
                <p class="small space">Item amounts leave out discounts ({{ rupees(discount) }}) and charges ({{ rupees(charges) }}); the totals above include them.</p>
                <table role="table">
                    <thead role="rowgroup"><tr role="row"><th v-for="head in ['Item', 'Bought', 'Used', 'Wasted']" :key="head" role="columnheader" scope="col">{{ head }}</th></tr></thead>
                    <tbody role="rowgroup">
                        <tr v-for="item in items" :key="item.id" role="row">
                            <td role="cell"><b>{{ item.name }}</b></td>
                            <td role="cell" data-l="Bought">{{ part(item, 'bought') }}</td>
                            <td role="cell" data-l="Used">{{ part(item, 'used') }}</td>
                            <td role="cell" data-l="Wasted">{{ part(item, 'wasted') }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
.word { min-width: 0; overflow-wrap: anywhere; }
.space { margin-bottom: .6rem; }
</style>
