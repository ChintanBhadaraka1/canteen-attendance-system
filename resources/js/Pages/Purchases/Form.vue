<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Banknote, Check, Landmark, Smartphone } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import LeaveGuard from '../../Components/LeaveGuard.vue';
import StockRows from '../../Components/StockRows.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { shortDate } from '../../format';
import { parseRupees, rowQuantity, rupees } from '../../stock';

const props = defineProps({
    items: { type: Array, required: true },
    shops: { type: Array, required: true },
    rows: { type: Array, required: true },
    shop: { type: Number, default: null },
    gone: { type: Array, required: true },
    token: { type: String, required: true },
});

const today = usePage().props.today;
const form = useForm({
    date: today,
    shop_id: props.shop ?? '',
    bill_number: '',
    different_bill: false,
    rows: props.rows.length ? props.rows.map((row) => ({ ...row, amount: '' })) : [{ item: '', quantity: '', unit: '', amount: '' }],
    discount: '',
    charges: '',
    paper_total: '',
    method: '',
    note: '',
    bill: null,
});
// Kept for the whole visit, so a repeat after a lost answer is the same save.
const token = props.token;

const itemOf = (row) => props.items.find(({ id }) => id === Number(row.item));
const filled = computed(() => form.rows.filter((row) => row.item || row.quantity || row.amount));
const sum = computed(() => form.rows.reduce((total, row) => total + (parseRupees(row.amount) ?? 0), 0));
const total = computed(() => sum.value - (parseRupees(form.discount) ?? 0) + (parseRupees(form.charges) ?? 0));
const paper = computed(() => parseRupees(form.paper_total));
const differs = computed(() => paper.value !== null && paper.value !== total.value);
const lines = computed(() => filled.value.filter((row) => itemOf(row)).map((row) => {
    const item = itemOf(row);
    const many = rowQuantity(row, item);
    const amount = parseRupees(row.amount);
    let warning = '';
    if (item.last && many && amount !== null) {
        const before = item.last.cost / item.last.quantity;
        const now = amount / many;
        if (now > before * 1.3) warning = `costs more than last time (${rupees(Math.round(before * 1000))} per ${item.unit})`;
        if (now < before * 0.7) warning = `costs less than last time (${rupees(Math.round(before * 1000))} per ${item.unit})`;
    }
    return { text: `${item.name} ${row.quantity || '?'} ${item.units.find(({ key }) => key === row.unit)?.label ?? ''}, ${amount === null ? '?' : rupees(amount)}`, warning };
}));
const methods = [{ key: 'cash', label: 'Cash', icon: Banknote }, { key: 'upi', label: 'UPI', icon: Smartphone }, { key: 'bank', label: 'Bank', icon: Landmark }];

const dialog = ref(null);
const unanswered = ref(false);
const changed = computed(() => filled.value.length > 0 || form.isDirty);
function ask() {
    form.clearErrors();
    form.rows.forEach((row, index) => {
        if (!row.item && !row.quantity && !row.amount) return;
        if (!row.item) form.setError(`rows.${index}.item`, `Row ${index + 1}: choose an item.`);
        if (!row.quantity) form.setError(`rows.${index}.quantity`, `Row ${index + 1}: fill in this box.`);
        else if (itemOf(row) && !rowQuantity(row, itemOf(row))) form.setError(`rows.${index}.quantity`, `Row ${index + 1}: Enter a quantity, for example 2.5.`);
        if (parseRupees(row.amount) === null) form.setError(`rows.${index}.amount`, `Row ${index + 1}: Enter an amount, for example 42.50.`);
    });
    ['discount', 'charges', 'paper_total'].forEach((key) => {
        if (form[key] && parseRupees(form[key]) === null) form.setError(key, 'Enter an amount, for example 42.50.');
    });
    if (!filled.value.length) form.setError('rows.0.item', 'Choose an item.');
    if (!form.method) form.setError('method', 'Choose cash, UPI or bank.');
    if (form.hasErrors) return focusFirstError();
    dialog.value.open();
}
function save() {
    if (form.processing) return;
    unanswered.value = false;
    form.transform((data) => ({ ...data, token })).post('/purchases', {
        preserveScroll: 'errors',
        onError: focusFirstError,
        onHttpException: () => { unanswered.value = true; return false; },
        onNetworkError: () => { unanswered.value = true; return false; },
        onFinish: () => dialog.value?.close(),
    });
}
</script>

<template>
    <AdminLayout title="New purchase" :back="{ href: '/purchases', label: 'Back to purchases' }">
        <p v-if="gone.length" class="panel warn">Left out, because they are turned off: {{ gone.join(', ') }}.</p>
        <p v-if="!items.length" class="panel">No stock items yet. Add one under Stock.</p>
        <form v-else class="stack" novalidate @submit.prevent="ask">
            <section class="panel formgrid">
                <FormField id="date" v-model="form.date" label="Date" type="date" :max="today" :error="form.errors.date" />
                <FormField id="shop_id" v-model="form.shop_id" as="select" label="Shop (optional)" :error="form.errors.shop_id">
                    <option value="">No shop</option>
                    <option v-for="one in shops" :key="one.id" :value="one.id">{{ one.name }}</option>
                </FormField>
                <FormField id="bill_number" v-model="form.bill_number" label="Bill number (optional)" maxlength="40" autocomplete="off" :error="form.errors.bill_number ?? form.errors.different_bill" />
                <label v-if="form.errors.different_bill || form.different_bill" class="check"><input v-model="form.different_bill" type="checkbox">It is a different bill</label>
            </section>
            <StockRows v-model="form.rows" :items="items" :errors="form.errors" amounts />
            <section class="panel formgrid">
                <FormField id="discount" v-model="form.discount" label="Discount on the bill (₹, optional)" inputmode="decimal" autocomplete="off" maxlength="11" :error="form.errors.discount" />
                <FormField id="charges" v-model="form.charges" label="Transport and other charges (₹, optional)" inputmode="decimal" autocomplete="off" maxlength="11" :error="form.errors.charges" />
                <FormField id="paper_total" v-model="form.paper_total" label="Total on paper bill (₹, optional)" inputmode="decimal" autocomplete="off" maxlength="11" :error="form.errors.paper_total" />
                <FormField id="note" v-model="form.note" label="Note (optional)" maxlength="200" autocomplete="off" :error="form.errors.note" />
                <div class="full after" aria-live="polite">
                    <p class="total">Total {{ rupees(total) }}</p>
                    <p v-if="differs" class="err">The rows add up to {{ rupees(total) }} but the paper bill says {{ rupees(paper) }}.</p>
                </div>
                <div class="full">
                    <fieldset class="seg three">
                        <legend>How was it paid?</legend>
                        <button v-for="one in methods" :key="one.key" type="button" :aria-pressed="form.method === one.key" :aria-invalid="form.errors.method ? 'true' : undefined" @click="form.method = one.key"><component :is="one.icon" aria-hidden="true" />{{ one.label }}</button>
                    </fieldset>
                    <p v-if="form.errors.method" class="err" role="alert">{{ form.errors.method }}</p>
                </div>
                <div class="full file">
                    <label class="label" for="bill">Photo of the bill (optional)</label>
                    <input id="bill" type="file" accept=".jpg,.jpeg,.png,.pdf" :aria-invalid="form.errors.bill ? 'true' : undefined" aria-describedby="bill-help" @change="form.bill = $event.target.files[0] ?? null">
                    <p id="bill-help" class="small">JPG, PNG or PDF, up to 5 MB.</p>
                    <p v-if="form.errors.bill" class="err" role="alert">{{ form.errors.bill }}</p>
                </div>
            </section>
            <p v-if="form.errors.stock || form.errors.token" class="err" role="alert">{{ form.errors.stock ?? 'This could not be saved. Reload the page and try again.' }}</p>
            <p v-if="unanswered" class="err" role="alert">No answer from the server. Save again: it is never saved twice.</p>
            <div class="acts start"><AppButton type="submit" :icon="Check" :disabled="form.processing">Review and save</AppButton></div>
        </form>
        <ConfirmDialog
            ref="dialog" :title="`Save purchase of ${rupees(total)}?`" :text="`${shortDate(form.date || today)}, paid by ${methods.find(({ key }) => key === form.method)?.label ?? ''}.`"
            confirm-label="Save" cancel-label="Go back" variant="primary" :icon="Check" :busy="form.processing" @confirm="save"
        >
            <ul class="rows list"><li v-for="(line, index) in lines" :key="index">{{ line.text }}<b v-if="line.warning" class="warn">{{ line.warning }}</b></li></ul>
        </ConfirmDialog>
        <LeaveGuard :active="changed" :text="`${filled.length} ${filled.length === 1 ? 'row' : 'rows'}, not saved yet.`" saves="/purchases" />
    </AdminLayout>
</template>

<style scoped>
.three { grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr)); min-width: 0; }
.total { font-size: 1.6rem; font-weight: 800; overflow-wrap: anywhere; }
.warn { color: var(--color-due); overflow-wrap: anywhere; }
p.warn { margin-bottom: 1.2rem; font-weight: 600; }
.list { max-height: 40vh; overflow: auto; margin-bottom: 1.2rem; overflow-wrap: anywhere; }
.file { min-width: 0; }
.file input { width: 100%; min-height: 3.1rem; padding: .4rem; border: 2px solid var(--color-field); border-radius: 8px; background: #fff; }
.file input::file-selector-button { min-height: 2.2rem; padding: 0 1rem; margin-right: .8rem; border-radius: 6px; background: var(--color-paper); font-weight: 650; cursor: pointer; }
</style>
