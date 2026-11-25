<script setup>
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Ban, Check } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import CancelDialog from '../../Components/CancelDialog.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DataList from '../../Components/DataList.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { shortDate } from '../../format';
import { parseRupees, rupees } from '../../stock';

const props = defineProps({
    date: { type: String, default: null },
    last: { type: String, default: null },
    cash: { type: Object, default: null },
    checks: { type: Object, required: true },
    token: { type: String, required: true },
});

const today = usePage().props.today;
const labels = { students: 'Received from students', walk_ins: 'Received from walk-ins', purchases: 'Purchases paid', expenses: 'Expenses paid', salaries: 'Salaries paid' };
const form = useForm({ opening: '', put_in: '', taken_out: '', counted: '', note: '' });

const opening = computed(() => props.cash?.opening ?? parseRupees(form.opening) ?? 0);
const expected = computed(() => opening.value + (props.cash?.change ?? 0) + (parseRupees(form.put_in) ?? 0) - (parseRupees(form.taken_out) ?? 0));
const difference = computed(() => (parseRupees(form.counted) === null ? null : parseRupees(form.counted) - expected.value));
const words = (amount) => (amount === 0 ? 'No difference' : `${rupees(Math.abs(amount))} ${amount > 0 ? 'more' : 'less'} than expected`);

const choose = (date) => router.get('/cash-check', { date }, { preserveState: true, preserveScroll: true });
const dialog = ref(null);
const unanswered = ref(false);
function ask() {
    form.clearErrors();
    ['opening', 'put_in', 'taken_out', 'counted'].forEach((key) => {
        if (form[key] && parseRupees(form[key]) === null) form.setError(key, 'Enter an amount, for example 42.50.');
    });
    if (props.cash.opening === null && !form.opening) form.setError('opening', 'Enter the cash at the start of the day.');
    if (!form.counted) form.setError('counted', 'Enter the cash you counted.');
    else if ((difference.value || parseRupees(form.put_in) || parseRupees(form.taken_out)) && !form.note.trim()) form.setError('note', 'Write a note about the difference, or about cash put in or taken out.');
    if (form.hasErrors) return focusFirstError();
    dialog.value.open();
}
function save() {
    if (form.processing) return;
    unanswered.value = false;
    form.transform((data) => ({ ...data, date: props.date, expected: expected.value, token: props.token })).post('/cash-check', {
        preserveScroll: 'errors',
        onSuccess: () => form.reset(),
        onError: focusFirstError,
        onHttpException: () => { unanswered.value = true; return false; },
        onNetworkError: () => { unanswered.value = true; return false; },
        onFinish: () => dialog.value?.close(),
    });
}

const columns = [
    { key: 'date', label: 'Date' },
    { key: 'expected', label: 'Expected' },
    { key: 'counted', label: 'Counted' },
    { key: 'difference', label: 'Result' },
];
const cancelDialog = ref(null);
const askCancel = (row) => cancelDialog.value.open(`/cash-check/${row.id}/cancel`, `Cancel the check of ${shortDate(row.date)}?`, 'The next check then starts from the one before.');
</script>

<template>
    <AdminLayout title="Cash check" :back="{ href: '/reports', label: 'Back to reports' }">
        <div class="filters">
            <FormField id="date" label="Check the cash up to" type="date" :max="today" :model-value="date ?? ''" @update:model-value="(value) => value && choose(value)" />
        </div>
        <p v-if="!date" class="panel">{{ last ? `Cash was already checked up to ${shortDate(last)}.` : 'This date cannot be checked.' }}</p>
        <form v-else class="two" novalidate @submit.prevent="ask">
            <section class="panel sum">
                <h2>{{ cash.from === date ? shortDate(date) : `${shortDate(cash.from)} to ${shortDate(date)}` }}</h2>
                <div><span>At the start</span><span>{{ cash.opening === null && !form.opening ? 'Type it' : rupees(opening) }}</span></div>
                <div v-for="(amount, key) in cash.lines" :key="key"><span>{{ labels[key] }}</span><span>{{ amount < 0 ? '−' : '+' }} {{ rupees(Math.abs(amount)) }}</span></div>
                <div><span>Put in</span><span>+ {{ rupees(parseRupees(form.put_in) ?? 0) }}</span></div>
                <div><span>Taken out</span><span>− {{ rupees(parseRupees(form.taken_out) ?? 0) }}</span></div>
                <div class="tot"><span>Expected</span><span>{{ rupees(expected) }}</span></div>
                <p v-if="expected < 0" class="err">Below zero: more cash went out than the app knows about.</p>
                <p class="after" aria-live="polite">{{ difference === null ? 'Type what you counted to see the difference.' : words(difference) }}</p>
                <p v-if="form.errors.expected" class="err" role="alert">{{ form.errors.expected }}</p>
            </section>
            <section class="panel stack">
                <FormField v-if="cash.opening === null" id="opening" v-model="form.opening" label="Cash at the start of the day (₹)" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors.opening" />
                <FormField id="put_in" v-model="form.put_in" label="Cash put in (₹, optional)" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors.put_in" />
                <FormField id="taken_out" v-model="form.taken_out" label="Cash taken out (₹, optional)" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors.taken_out" />
                <FormField id="counted" v-model="form.counted" label="Cash counted (₹)" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors.counted" />
                <FormField id="note" v-model="form.note" label="Note" maxlength="200" autocomplete="off" :error="form.errors.note" />
                <p v-if="form.errors.date || form.errors.cash || form.errors.token" class="err" role="alert">{{ form.errors.date ?? form.errors.cash ?? 'Reload the page and try again.' }}</p>
                <p v-if="unanswered" class="err" role="alert">No answer from the server. Save again: it is never saved twice.</p>
                <AppButton type="submit" :icon="Check" :disabled="form.processing">Save check</AppButton>
            </section>
        </form>
        <h2 class="earlier">Earlier checks</h2>
        <DataList :columns="columns" :rows="checks" :filters="{}" noun="check">
            <template #cell-date="{ row }"><b>{{ shortDate(row.date) }}</b><br><span class="small">{{ [row.note, `by ${row.user_name}`].filter(Boolean).join(', ') }}</span></template>
            <template #cell-expected="{ row }">{{ rupees(row.expected) }}</template>
            <template #cell-counted="{ row }">{{ rupees(row.counted) }}</template>
            <template #cell-difference="{ row }">
                <StatusTag v-if="row.cancelled" kind="due">Cancelled</StatusTag>
                <StatusTag v-else :kind="row.difference ? 'due' : 'ok'">{{ words(row.difference) }}</StatusTag>
                <template v-if="row.changed"><br><b>Changed after this check</b>{{ `: now ${rupees(row.now)} expected` }}</template>
                <template v-if="row.cancel_reason"><br><span class="small">{{ row.cancel_reason }}</span></template>
            </template>
            <template #actions="{ row }"><AppButton v-if="row.cancellable" variant="danger" :icon="Ban" @click="askCancel(row)">Cancel<span class="sr-only"> check of {{ shortDate(row.date) }}</span></AppButton></template>
        </DataList>
        <ConfirmDialog
            ref="dialog" :title="`Save ${form.counted ? rupees(parseRupees(form.counted) ?? 0) : ''} counted?`" :text="difference === null ? '' : `${words(difference)}.`"
            confirm-label="Save check" cancel-label="Go back" variant="primary" :icon="Check" :busy="form.processing" @confirm="save"
        />
        <CancelDialog ref="cancelDialog" />
    </AdminLayout>
</template>

<style scoped>
.earlier { margin: 1.6rem 0 .8rem; }
</style>
