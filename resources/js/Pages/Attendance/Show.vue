<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { ClipboardCheck, Download, Trash2 } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DataList from '../../Components/DataList.vue';
import DateRange from '../../Components/DateRange.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { displayName, money, shortDate } from '../../format';

const props = defineProps({
    student: { type: Object, required: true },
    canMark: Boolean,
    rows: { type: Object, required: true },
    filters: { type: Object, required: true },
    today: { type: String, required: true },
});

const columns = [
    { key: 'date', label: 'Date', sortable: true },
    { key: 'meal', label: 'Meal' },
    { key: 'amount', label: 'Price' },
    { key: 'extra_amount', label: 'Extras' },
    { key: 'is_paid', label: 'Payment' },
];

const list = ref(null);
const from = ref(props.filters.from);
const to = ref(props.filters.to);
watch(() => [props.filters.from, props.filters.to], ([newFrom, newTo]) => {
    from.value = newFrom;
    to.value = newTo;
});
const show = (range) => list.value.visit(range);

const iso = (date) => date.toISOString().slice(0, 10);
const [year, month, day] = props.today.split('-').map(Number);
const utc = (m, d) => iso(new Date(Date.UTC(year, m - 1, d)));
const ranges = [
    { label: 'Last 7 days', from: utc(month, day - 6), to: props.today },
    { label: 'Last 30 days', from: utc(month, day - 29), to: props.today },
    { label: 'This month', from: utc(month, 1), to: props.today },
    { label: 'Last month', from: utc(month - 1, 1), to: utc(month, 0) },
];
const isShown = (range) => range.from === props.filters.from && range.to === props.filters.to;
const downloadUrl = computed(() => '/student-attendance/user/download?'
    + new URLSearchParams({ user_id: props.student.id, from: props.filters.from, to: props.filters.to }));

const dialog = ref(null);
const chosen = ref(null);
const busy = ref(false);

function ask(row) {
    chosen.value = row;
    dialog.value.open();
}
const remove = () => router.post('/student-attendance/delete', { id: chosen.value.id, from: props.filters.from, to: props.filters.to }, {
    preserveScroll: true,
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        dialog.value?.close();
    },
});
</script>

<template>
    <AdminLayout :title="`History of ${displayName(student)}`" :back="{ href: '/student-attendance', label: 'Back to attendance' }">
        <template v-if="canMark" #actions>
            <AppButton :icon="ClipboardCheck" :href="`/student-attendance/create/${student.id}`">Mark attendance</AppButton>
        </template>
        <section class="panel stack dates" aria-label="Dates">
            <div class="acts start">
                <AppButton v-for="range in ranges" :key="range.label" variant="plain" :aria-pressed="isShown(range)" @click="show({ from: range.from, to: range.to })">{{ range.label }}</AppButton>
            </div>
            <form class="filters" @submit.prevent="show({ from, to })">
                <DateRange id="history" v-model:from="from" v-model:to="to" />
                <AppButton type="submit" variant="plain" :disabled="!from || !to">Show these dates</AppButton>
                <AppButton variant="plain" :icon="Download" :href="downloadUrl" external>Download</AppButton>
            </form>
            <p>Showing {{ shortDate(filters.from) }} to {{ shortDate(filters.to) }}.</p>
        </section>
        <DataList
            ref="list" :columns="columns" :rows="rows" :filters="filters" noun="entry" plural="entries"
            search-label="Find a meal" search-hint="Meal name"
        >
            <template #empty>No attendance in these dates.</template>
            <template #cell-date="{ row }"><b>{{ shortDate(row.date) }}</b></template>
            <template #cell-amount="{ row }">{{ money(row.amount) }}</template>
            <template #cell-extra_amount="{ row }">{{ Number(row.extra_amount) ? money(row.extra_amount) : 'None' }}</template>
            <template #cell-is_paid="{ row }">
                <StatusTag :kind="row.is_paid ? 'ok' : 'due'">{{ row.is_paid ? 'Paid' : 'Not paid' }}</StatusTag>
            </template>
            <template #actions="{ row }">
                <AppButton v-if="!row.is_paid" variant="danger" :icon="Trash2" @click="ask(row)">Delete<span class="sr-only"> {{ row.meal }} on {{ shortDate(row.date) }}</span></AppButton>
            </template>
        </DataList>
        <ConfirmDialog
            ref="dialog" :title="chosen ? `Delete ${chosen.meal} on ${shortDate(chosen.date)}?` : ''"
            :text="chosen ? `${money(Number(chosen.amount) + Number(chosen.extra_amount))} will be taken off the bill of ${displayName(student)}.` : ''"
            confirm-label="Delete entry" cancel-label="Keep entry" :icon="Trash2" :busy="busy" @confirm="remove"
        />
    </AdminLayout>
</template>

<style scoped>
.dates { margin-bottom: 1.2rem; }
.dates .filters { margin-bottom: 0; }
.dates .btn[aria-pressed="true"] { background: var(--color-navy-900); border-color: var(--color-navy-900); color: #fff; }
</style>
