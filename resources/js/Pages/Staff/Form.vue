<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

const props = defineProps({
    staff: { type: Object, default: null },
    jobs: { type: Array, required: true },
});
const today = usePage().props.today;
const form = useForm({
    name: props.staff?.name ?? '', phone: props.staff?.phone ?? '', job_id: props.staff?.job_id ?? '', note: props.staff?.note ?? '',
    joined_on: props.staff?.joined_on ?? today, ...(props.staff ? {} : { salary: '' }),
});
const back = props.staff ? `/staff/${props.staff.id}` : '/staff';
const save = () => (props.staff ? form.put(back, { onError: focusFirstError }) : form.post(back, { onError: focusFirstError }));
</script>

<template>
    <AdminLayout :title="staff ? `Edit ${staff.name}` : 'Add staff'" :back="{ href: back, label: staff ? 'Back to this person' : 'Back to staff' }">
        <form class="panel formgrid" novalidate @submit.prevent="save">
            <FormField id="name" v-model="form.name" label="Name" maxlength="60" autocomplete="off" :error="form.errors.name" />
            <FormField id="phone" v-model="form.phone" label="Phone (optional)" inputmode="numeric" maxlength="10" autocomplete="off" :error="form.errors.phone" />
            <FormField id="job_id" v-model="form.job_id" as="select" label="Job" :error="form.errors.job_id">
                <option value="">Not given</option>
                <option v-for="one in jobs" :key="one.id" :value="one.id">{{ one.name }}</option>
            </FormField>
            <FormField id="joined_on" v-model="form.joined_on" label="Joining date" type="date" :max="today" :error="form.errors.joined_on" />
            <FormField v-if="!staff" id="salary" v-model="form.salary" label="Salary for a month (₹)" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors.salary" />
            <FormField id="note" v-model="form.note" label="Note (optional)" maxlength="200" autocomplete="off" :error="form.errors.note" />
            <div class="full acts start">
                <AppButton type="submit" :icon="Save" :disabled="form.processing">Save</AppButton>
                <AppButton variant="plain" :href="back">Back</AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
