<script setup>
import { computed, nextTick, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Ban, Check, Gift, MinusCircle, Pencil, TrendingUp, UserCheck, UserX, Wallet } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import CancelDialog from '../../Components/CancelDialog.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import MethodButtons from '../../Components/MethodButtons.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { monthName, shortDate } from '../../format';
import { rupees } from '../../stock';

const props = defineProps({
    staff: { type: Object, required: true },
    periods: { type: Array, required: true },
    rates: { type: Array, required: true },
    months: { type: Array, required: true },
    entries: { type: Array, required: true },
    choices: { type: Array, required: true },
    token: { type: String, required: true },
});

const today = usePage().props.today;
const now = today.slice(0, 7);
const titles = { payment: 'Pay salary', bonus: 'Bonus', deduction: 'Deduction', rate: 'Change salary' };
const what = ref('');
const form = useForm({ type: '', month: '', date: today, amount: '', method: '', note: '', token: '' });
const left = computed(() => props.months.find(({ month }) => month === form.month)?.left);

async function start(type) {
    what.value = type;
    form.reset();
    form.clearErrors();
    form.type = type;
    form.month = props.choices.includes(now) ? now : (props.choices[0] ?? '');
    form.token = props.token;
    await nextTick();
    document.getElementById('month')?.focus();
}
function save() {
    if (form.processing) return;
    form.post(`/staff/${props.staff.id}/${what.value === 'rate' ? 'rates' : 'entries'}`, {
        preserveScroll: true, onSuccess: () => { what.value = ''; }, onError: focusFirstError,
    });
}

const cancelDialog = ref(null);
const askCancel = (row) => cancelDialog.value.open(`/staff/entries/${row.id}/cancel`, `Cancel ${row.label.toLowerCase()}?`, `${rupees(row.amount)} for ${monthName(row.month)}.`);

const statusDialog = ref(null);
const status = useForm({ status: '', date: today });
function askStatus() {
    status.reset();
    status.clearErrors();
    status.status = props.staff.working ? 'left' : 'working';
    statusDialog.value.open();
}
function saveStatus() {
    if (status.processing) return;
    status.patch(`/staff/${props.staff.id}/status`, { preserveScroll: true, onSuccess: () => statusDialog.value?.close(), onError: focusFirstError });
}
</script>

<template>
    <AdminLayout :title="staff.name" :back="{ href: '/staff', label: 'Back to staff' }">
        <div class="acts start bar-space">
            <AppButton :icon="Wallet" @click="start('payment')">Pay salary</AppButton>
            <AppButton variant="plain" :icon="Gift" @click="start('bonus')">Bonus</AppButton>
            <AppButton variant="plain" :icon="MinusCircle" @click="start('deduction')">Deduction</AppButton>
            <AppButton variant="plain" :icon="TrendingUp" @click="start('rate')">Change salary</AppButton>
            <AppButton variant="plain" :icon="Pencil" :href="`/staff/${staff.id}/edit`">Edit</AppButton>
            <AppButton v-if="staff.working" variant="danger" :icon="UserX" @click="askStatus">Mark as left</AppButton>
            <AppButton v-else variant="plain" :icon="UserCheck" @click="askStatus">Works here again</AppButton>
        </div>
        <form v-if="what" class="panel formgrid entry" novalidate @submit.prevent="save">
            <h2 class="full">{{ titles[what] }}</h2>
            <FormField id="month" v-model="form.month" as="select" :label="what === 'rate' ? 'From which month?' : 'For which month?'" :error="form.errors.month">
                <option v-for="one in choices" :key="one" :value="one">{{ monthName(one) }}{{ one > now ? ' (later)' : '' }}</option>
            </FormField>
            <FormField
                id="amount" v-model="form.amount" :label="what === 'rate' ? 'Salary for a month (₹)' : 'Amount (₹)'" inputmode="decimal" maxlength="11" autocomplete="off"
                :help="what === 'payment' && left !== undefined ? `${rupees(left)} left to pay for this month.` : undefined" :error="form.errors.amount"
            />
            <FormField v-if="what !== 'rate'" id="date" v-model="form.date" label="Date" type="date" :max="today" :error="form.errors.date" />
            <FormField v-if="what !== 'rate'" id="note" v-model="form.note" :label="what === 'payment' ? 'Note (optional)' : 'What is it for?'" maxlength="200" autocomplete="off" :error="form.errors.note" />
            <MethodButtons v-if="what === 'payment'" v-model="form.method" class="full" :error="form.errors.method" />
            <p v-if="form.errors.entry || form.errors.token" class="err full" role="alert">{{ form.errors.entry ?? 'Reload the page and try again.' }}</p>
            <div class="full acts start">
                <AppButton type="submit" :icon="Check" :disabled="form.processing">Save</AppButton>
                <AppButton variant="plain" @click="what = ''">Close</AppButton>
            </div>
        </form>
        <div class="two">
            <section class="panel sum">
                <h2>Details <StatusTag :kind="staff.working ? 'ok' : 'due'">{{ staff.working ? 'Working' : 'Left' }}</StatusTag></h2>
                <div><span>Job</span><span class="word">{{ staff.job ?? 'Not given' }}</span></div>
                <div><span>Phone</span><span>{{ staff.phone ?? 'Not given' }}</span></div>
                <div v-for="one in periods" :key="one.id"><span>Worked</span><span>{{ shortDate(one.joined_on) }} to {{ one.left_on ? shortDate(one.left_on) : 'now' }}</span></div>
                <p v-if="staff.note" class="word">{{ staff.note }}</p>
            </section>
            <section class="panel sum">
                <h2>Salary history</h2>
                <div v-for="one in rates" :key="one.from_month"><span>From {{ monthName(one.from_month) }}</span><span>{{ rupees(one.amount) }}</span></div>
            </section>
        </div>
        <section class="space">
            <h2>Months</h2>
            <p v-if="!months.length">None yet.</p>
            <table v-else role="table">
                <thead role="rowgroup"><tr role="row"><th v-for="head in ['Month', 'Due', 'Paid', 'Left']" :key="head" role="columnheader" scope="col">{{ head }}</th></tr></thead>
                <tbody role="rowgroup">
                    <tr v-for="one in months" :key="one.month" role="row">
                        <td role="cell"><b>{{ monthName(one.month) }}</b></td>
                        <td role="cell" data-l="Due">{{ rupees(one.due) }}<template v-if="one.bonus || one.deduction"><br><span class="small">{{ [one.bonus ? `bonus ${rupees(one.bonus)}` : '', one.deduction ? `deduction ${rupees(one.deduction)}` : ''].filter(Boolean).join(', ') }}</span></template></td>
                        <td role="cell" data-l="Paid">{{ rupees(one.paid) }}</td>
                        <td role="cell" data-l="Left"><StatusTag :kind="one.left ? 'due' : 'ok'">{{ one.left ? `${rupees(one.left)} left` : 'Paid' }}</StatusTag></td>
                    </tr>
                </tbody>
            </table>
        </section>
        <section v-if="entries.length" class="panel space">
            <h2>Latest entries</h2>
            <ul class="rows">
                <li v-for="row in entries" :key="row.id">
                    <span class="word">
                        <b>{{ row.label }} {{ rupees(row.amount) }}</b> for {{ monthName(row.month) }}
                        <StatusTag v-if="row.cancelled" kind="due">Cancelled</StatusTag><br>
                        <span class="small">{{ [shortDate(row.date), row.method, row.note, row.cancel_reason, `by ${row.user_name}`].filter(Boolean).join(', ') }}</span>
                    </span>
                    <AppButton v-if="!row.cancelled" variant="danger" :icon="Ban" @click="askCancel(row)">Cancel<span class="sr-only"> {{ row.label }} {{ rupees(row.amount) }}</span></AppButton>
                </li>
            </ul>
        </section>
        <CancelDialog ref="cancelDialog" />
        <ConfirmDialog
            ref="statusDialog" :title="staff.working ? `Mark ${staff.name} as left?` : `Does ${staff.name} work here again?`"
            :text="staff.working ? 'The full salary of the leaving month stays due. Use a deduction for part of a month.' : ''" confirm-label="Save" cancel-label="Go back"
            variant="primary" :icon="Check" :busy="status.processing" @confirm="saveStatus"
        >
            <FormField id="status-date" v-model="status.date" :label="staff.working ? 'Last day' : 'First day'" type="date" :max="today" :error="status.errors.date" />
        </ConfirmDialog>
    </AdminLayout>
</template>

<style scoped>
.bar-space, .entry { margin-bottom: 1.2rem; }
.space { margin-top: 1.5rem; }
.space h2 { margin-bottom: .6rem; }
.word { min-width: 0; overflow-wrap: anywhere; }
</style>
