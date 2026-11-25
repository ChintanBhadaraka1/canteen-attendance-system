<script setup>
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Ban } from '@lucide/vue';
import AppButton from './AppButton.vue';
import ConfirmDialog from './ConfirmDialog.vue';
import FormField from './FormField.vue';
import StatusTag from './StatusTag.vue';
import { focusFirstError } from '../forms';
import { money, shortDate } from '../format';

const props = defineProps({
    rows: { type: Array, required: true },
    // Where the server goes back to after a cancel.
    back: { type: Object, required: true },
    dates: Boolean,
});

const plates = (row) => `${row.plates} ${row.plates === 1 ? 'plate' : 'plates'} of ${row.meal}`;
const who = (row) => (row.type === 'walk_in' ? 'Walk-in' : row.student);
const way = (row) => (row.method === 'upi' ? 'UPI' : 'cash');
const extras = (row) => row.extras.map((extra) => `${extra.name} × ${extra.quantity} (${money(extra.total)})`).join(', ');
const canCancel = (row) => row.status === 'unpaid' || (row.type === 'walk_in' && row.status === 'paid');

const dialog = ref(null);
const chosen = ref(null);
const form = useForm({ reason: '' });
function ask(row) {
    chosen.value = row;
    form.reset();
    form.clearErrors();
    dialog.value.open();
}
function cancel() {
    if (form.processing) return;
    form.transform((data) => ({ ...data, ...props.back })).post(`/student-attendance/guest/${chosen.value.id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => dialog.value?.close(),
        onError: focusFirstError,
    });
}
</script>

<template>
    <ul class="rows guests">
        <li v-for="row in rows" :key="row.id">
            <span class="what">
                <b>{{ plates(row) }}</b>, {{ money(row.amount) }}<template v-if="row.type === 'walk_in'"> by {{ way(row) }}</template>
                <template v-if="dates"><br>{{ shortDate(row.date) }}</template>
                <template v-else><br>{{ who(row) }}</template>
                <template v-if="row.guest_name"><br>Guest: {{ row.guest_name }}</template>
                <template v-if="row.extras.length"><br>{{ extras(row) }}</template>
                <template v-if="row.reason"><br><span class="small">{{ row.reason }}</span></template>
            </span>
            <span class="acts">
                <StatusTag :kind="row.status === 'paid' ? 'ok' : 'due'">{{ { paid: 'Paid', unpaid: 'Not paid', cancelled: 'Cancelled' }[row.status] }}</StatusTag>
                <AppButton v-if="canCancel(row)" variant="danger" :icon="Ban" @click="ask(row)">Cancel<span class="sr-only"> {{ plates(row) }}</span></AppButton>
            </span>
        </li>
    </ul>
    <ConfirmDialog
        ref="dialog" :title="chosen ? `Cancel ${plates(chosen)}?` : ''"
        :text="chosen ? (chosen.type === 'walk_in' ? `Give back ${money(chosen.amount)} by ${way(chosen)}.` : `${money(chosen.amount)} will be taken off the bill.`) : ''"
        confirm-label="Cancel plates" cancel-label="Keep plates" :icon="Ban" :busy="form.processing" @confirm="cancel"
    >
        <FormField id="reason" v-model="form.reason" label="Why is it cancelled?" maxlength="200" autocomplete="off" required :error="form.errors.reason" @keydown.enter.prevent="cancel" />
    </ConfirmDialog>
</template>

<style scoped>
.what { min-width: 0; overflow-wrap: anywhere; }
.guests .acts { align-items: center; }
</style>
