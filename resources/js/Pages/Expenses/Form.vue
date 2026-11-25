<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import LeaveGuard from '../../Components/LeaveGuard.vue';
import MethodButtons from '../../Components/MethodButtons.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

const props = defineProps({
    types: { type: Array, required: true },
    token: { type: String, required: true },
});
const today = usePage().props.today;
const form = useForm({ type_id: '', date: today, amount: '', method: '', paid_to: '', note: '', token: props.token });
const gas = computed(() => props.types.find(({ id }) => id === Number(form.type_id))?.name.toLowerCase() === 'gas');
const unanswered = ref(false);
function save() {
    if (form.processing) return;
    unanswered.value = false;
    form.post('/expenses', {
        onError: focusFirstError,
        onHttpException: () => { unanswered.value = true; return false; },
        onNetworkError: () => { unanswered.value = true; return false; },
    });
}
</script>

<template>
    <AdminLayout title="Add expense" :back="{ href: '/expenses', label: 'Back to expenses' }">
        <form class="panel formgrid" novalidate @submit.prevent="save">
            <FormField id="type_id" v-model="form.type_id" as="select" label="Type" :error="form.errors.type_id" :help="gas ? 'If you enter gas under Purchases, do not add it here too.' : undefined">
                <option value="">Choose</option>
                <option v-for="one in types" :key="one.id" :value="one.id">{{ one.name }}</option>
            </FormField>
            <FormField id="amount" v-model="form.amount" label="Amount (₹)" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors.amount" />
            <FormField id="date" v-model="form.date" label="Date" type="date" :max="today" :error="form.errors.date" />
            <FormField id="paid_to" v-model="form.paid_to" label="Paid to (optional)" maxlength="60" autocomplete="off" :error="form.errors.paid_to" />
            <FormField id="note" v-model="form.note" label="Note (optional)" maxlength="200" autocomplete="off" :error="form.errors.note" />
            <MethodButtons v-model="form.method" class="full" :error="form.errors.method" />
            <p v-if="form.errors.expense || form.errors.token" class="err full" role="alert">{{ form.errors.expense ?? 'Reload the page and try again.' }}</p>
            <p v-if="unanswered" class="err full" role="alert">No answer from the server. Save again: it is never saved twice.</p>
            <div class="full acts start">
                <AppButton type="submit" :icon="Save" :disabled="form.processing">Save expense</AppButton>
                <AppButton variant="plain" href="/expenses">Back</AppButton>
            </div>
        </form>
        <LeaveGuard :active="form.isDirty" text="This expense is not saved yet." saves="/expenses" />
    </AdminLayout>
</template>
