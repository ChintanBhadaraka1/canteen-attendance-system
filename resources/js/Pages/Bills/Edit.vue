<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Banknote, Check, Download, Smartphone } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { displayName, money, shortDate } from '../../format';

const props = defineProps({
    student: { type: Object, required: true },
    bill: { type: Object, required: true },
    receipts: { type: Array, required: true },
    month: { type: String, required: true },
});

const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const name = computed(() => displayName(props.student));
const dialog = ref(null);

const form = useForm({ student_id: props.student.id, amount: '', type: '', month: props.month });

const after = computed(() => {
    const paid = Number(form.amount);
    if (form.amount === '' || !(paid > 0)) return 'Type the amount to see what is left.';
    const left = props.bill.due - paid;
    if (left > 0) return `After this payment, ${money(left)} is still due.`;
    if (left < 0) return `After this payment, ${money(-left)} is kept as advance.`;
    return 'After this payment, nothing is due.';
});
const way = computed(() => ({ cash: 'cash', upi: 'UPI' })[form.type] ?? '');

function ask() {
    form.clearErrors();
    if (form.amount === '') form.setError('amount', 'Enter the amount received.');
    else if (!Number.isInteger(Number(form.amount)) || Number(form.amount) < 1) form.setError('amount', 'Enter whole rupees, ₹1 or more.');
    if (!form.type) form.setError('type', 'Choose cash or UPI.');
    if (form.hasErrors) return focusFirstError();
    dialog.value.open();
}
const save = () => form.put(`/bill/${props.bill.id}`, {
    onError: () => {
        dialog.value?.close();
        focusFirstError();
    },
    onFinish: () => dialog.value?.close(),
});
</script>

<template>
    <AdminLayout :title="bill.due > 0 ? `Take payment from ${name}` : `Bill of ${name}`" :back="{ href: '/bill', label: 'Back to bills' }">
        <div class="two">
            <form v-if="bill.due > 0" class="panel stack" novalidate @submit.prevent="ask">
                <FormField id="amount" v-model="form.amount" class="amount" label="Amount received (₹)" type="number" inputmode="numeric" min="1" step="1" autocomplete="off" :error="form.errors.amount" />
                <div>
                    <fieldset class="seg">
                        <legend>How was it paid?</legend>
                        <button type="button" :aria-pressed="form.type === 'cash'" :aria-invalid="form.errors.type ? 'true' : undefined" @click="form.type = 'cash'"><Banknote aria-hidden="true" />Cash</button>
                        <button type="button" :aria-pressed="form.type === 'upi'" @click="form.type = 'upi'"><Smartphone aria-hidden="true" />UPI</button>
                    </fieldset>
                    <p v-if="form.errors.type" class="err" role="alert">{{ form.errors.type }}</p>
                </div>
                <FormField id="month" v-model="form.month" as="select" label="For which month?" :error="form.errors.month">
                    <option v-for="option in months" :key="option">{{ option }}</option>
                </FormField>
                <p class="after" aria-live="polite">{{ after }}</p>
                <AppButton type="submit" :icon="Check" :disabled="form.processing">Save payment</AppButton>
            </form>
            <p v-else class="after">
                {{ bill.due < 0 ? `Nothing is due. ${money(-bill.due)} is kept as advance.` : 'Nothing is due.' }}
            </p>
            <div class="stack">
                <section class="panel">
                    <h2>The bill</h2>
                    <div class="sum">
                        <div><span>Meals</span><span>{{ money(bill.amount) }}</span></div>
                        <div><span>Pending from before</span><span>{{ money(bill.pending_amount) }}</span></div>
                        <div><span>Advance paid</span><span>− {{ money(bill.advance_amount) }}</span></div>
                        <div class="tot"><span>To collect</span><span>{{ money(Math.max(0, bill.due)) }}</span></div>
                    </div>
                </section>
                <section class="panel">
                    <h2>Receipts</h2>
                    <ul v-if="receipts.length" class="rows">
                        <li v-for="receipt in receipts" :key="receipt.id">
                            <span>{{ receipt.month_name }}<br><span class="small">Paid on {{ shortDate(receipt.payment_date) }}</span></span>
                            <AppButton variant="plain" :icon="Download" :href="`/bill/${receipt.id}/receipt`" external>Download receipt<span class="sr-only"> for {{ receipt.month_name }}</span></AppButton>
                        </li>
                    </ul>
                    <p v-else>No receipts yet.</p>
                </section>
            </div>
        </div>
        <ConfirmDialog
            ref="dialog" :title="`Save ${money(form.amount)} by ${way} from ${name}?`" :text="after"
            confirm-label="Save payment" cancel-label="Go back" variant="primary" :icon="Check" :busy="form.processing" @confirm="save"
        />
    </AdminLayout>
</template>

<style scoped>
.stack :deep(#amount) { font-size: 1.6rem; font-weight: 700; }
</style>
