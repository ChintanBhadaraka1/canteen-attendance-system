<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import LeaveGuard from '../../Components/LeaveGuard.vue';
import StockRows from '../../Components/StockRows.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { shortDate } from '../../format';

const props = defineProps({
    type: { type: String, required: true },
    items: { type: Array, required: true },
    reasons: { type: Array, required: true },
    token: { type: String, required: true },
});

const today = usePage().props.today;
const waste = props.type === 'waste';
const form = useForm({ date: today, rows: [{ item: '', quantity: '', unit: '' }], reason_id: '', reason_text: '', note: '' });
// Kept for the whole visit, so a repeat after a lost answer is the same save.
const token = props.token;

const itemOf = (row) => props.items.find(({ id }) => id === Number(row.item));
const filled = computed(() => form.rows.filter((row) => row.item || row.quantity));
const summary = computed(() => filled.value.filter((row) => itemOf(row)).map((row) => {
    const item = itemOf(row);
    return `${item.name} ${row.quantity || '?'} ${item.units.find(({ key }) => key === row.unit)?.label ?? ''}`;
}));
const other = computed(() => props.reasons.find(({ id }) => id === Number(form.reason_id))?.name.toLowerCase() === 'other');

const dialog = ref(null);
const unanswered = ref(false);
function ask() {
    form.clearErrors();
    if (!filled.value.length) form.setError('rows.0.item', 'Choose an item.');
    if (waste && !form.reason_id) form.setError('reason_id', 'Choose a reason.');
    if (form.hasErrors) return focusFirstError();
    dialog.value.open();
}
function save() {
    if (form.processing) return;
    unanswered.value = false;
    form.transform((data) => ({ ...data, token })).post(`/stock/${props.type}`, {
        preserveScroll: 'errors',
        onError: focusFirstError,
        onHttpException: () => { unanswered.value = true; return false; },
        onNetworkError: () => { unanswered.value = true; return false; },
        onFinish: () => dialog.value?.close(),
    });
}
</script>

<template>
    <AdminLayout :title="waste ? 'Wasted' : 'Used in the kitchen'" :back="{ href: '/stock', label: 'Back to stock' }">
        <p v-if="!items.length" class="panel">No stock items yet. Add one under Stock.</p>
        <form v-else class="stack" novalidate @submit.prevent="ask">
            <section class="panel formgrid">
                <FormField id="date" v-model="form.date" label="Date" type="date" :max="today" :error="form.errors.date" />
                <FormField v-if="waste" id="reason_id" v-model="form.reason_id" as="select" label="Why was it wasted?" :error="form.errors.reason_id">
                    <option value="">Choose</option>
                    <option v-for="reason in reasons" :key="reason.id" :value="reason.id">{{ reason.name }}</option>
                </FormField>
                <FormField v-if="waste" id="reason_text" v-model="form.reason_text" :label="other ? 'What happened?' : 'More about it (optional)'" maxlength="200" autocomplete="off" :error="form.errors.reason_text" />
                <FormField id="note" v-model="form.note" label="Note (optional)" maxlength="200" autocomplete="off" :error="form.errors.note" />
            </section>
            <StockRows v-model="form.rows" :items="items" :errors="form.errors" />
            <p v-if="form.errors.stock || form.errors.token" class="err" role="alert">{{ form.errors.stock ?? 'This could not be saved. Reload the page and try again.' }}</p>
            <p v-if="unanswered" class="err" role="alert">No answer from the server. Save again: it is never saved twice.</p>
            <div class="acts start"><AppButton type="submit" :icon="Check" :disabled="form.processing">Review and save</AppButton></div>
        </form>
        <ConfirmDialog
            ref="dialog" :title="waste ? 'Save as wasted?' : 'Save as used?'" :text="`${shortDate(form.date || today)}`"
            confirm-label="Save" cancel-label="Go back" variant="primary" :icon="Check" :busy="form.processing" @confirm="save"
        >
            <ul class="rows list"><li v-for="(line, index) in summary" :key="index">{{ line }}</li></ul>
        </ConfirmDialog>
        <LeaveGuard :active="filled.length > 0" :text="`${filled.length} ${filled.length === 1 ? 'row' : 'rows'}, not saved yet.`" saves="/stock" />
    </AdminLayout>
</template>

<style scoped>
.list { max-height: 40vh; overflow: auto; margin-bottom: 1.2rem; overflow-wrap: anywhere; }
</style>
