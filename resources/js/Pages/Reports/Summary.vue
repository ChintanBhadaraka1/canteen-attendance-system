<script setup>
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Download, Printer } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { lastMonths, monthName } from '../../format';
import { rupees } from '../../stock';

const props = defineProps({ months: { type: Array, required: true } });
const now = computed(() => props.months[0]);
const before = computed(() => props.months[1]);
const choices = lastMonths(usePage().props.today, 24);
const list = computed(() => (choices.includes(now.value.month) ? choices : [now.value.month, ...choices]));
const show = (amount) => (amount === null ? 'Not available' : rupees(amount));
const parts = [
    { key: 'left', title: 'Money in and out', total: 'left_over', label: 'Money left over' },
    { key: 'right', title: 'Cost of running', total: 'profit', label: 'Estimated profit' },
];
const print = () => window.print();
</script>

<template>
    <AdminLayout title="Month summary" :back="{ href: '/reports', label: 'Back to reports' }">
        <template #actions>
            <AppButton variant="plain" :icon="Download" :href="`/reports/summary/download?month=${now.month}`" external>Download</AppButton>
            <AppButton variant="plain" :icon="Printer" @click="print">Print</AppButton>
        </template>
        <div class="filters">
            <FormField id="month" as="select" label="Month" :model-value="now.month" @update:model-value="(month) => router.get('/reports/summary', { month }, { preserveScroll: true })">
                <option v-for="one in list" :key="one" :value="one">{{ monthName(one) }}</option>
            </FormField>
        </div>
        <h2 class="title">{{ monthName(now.month) }}</h2>
        <div class="two">
            <section v-for="part in parts" :key="part.key" class="panel sum">
                <h2>{{ part.title }}</h2>
                <div v-for="(line, index) in now[part.key]" :key="line.key">
                    <span>{{ line.label }}<br><span class="small">{{ show(before[part.key][index].amount) }} the month before</span></span>
                    <Link v-if="line.href" :href="line.href" class="amount">{{ show(line.amount) }}<span class="sr-only">, see the list</span></Link>
                    <span v-else>{{ show(line.amount) }}</span>
                </div>
                <div class="tot"><span>{{ part.label }}</span><span>{{ show(now[part.total]) }}</span></div>
                <p class="small">{{ show(before[part.total]) }} the month before</p>
            </section>
        </div>
        <section class="panel sum space">
            <div><span>Meals served</span><span>{{ now.served }} <span class="small">({{ before.served }} the month before)</span></span></div>
            <div><span>Food cost per meal</span><span>{{ show(now.cost_per_meal) }} <span class="small">({{ show(before.cost_per_meal) }} the month before)</span></span></div>
            <p>Bought food not yet used is in Purchases, not in Food used.</p>
            <p v-if="now.no_use"><b>No kitchen use was entered, so profit is too high.</b></p>
        </section>
    </AdminLayout>
</template>

<style scoped>
.title { margin-bottom: .8rem; }
.space { margin-top: 1.5rem; }
.two .sum > div { flex-wrap: wrap; }
.two .sum > div > :last-child { white-space: nowrap; }
.amount { display: inline-flex; align-items: center; min-height: 48px; text-decoration: underline; }
</style>
