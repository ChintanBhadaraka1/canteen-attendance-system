<script setup>
import { useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import PhotoField from '../../Components/PhotoField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { displayName } from '../../format';

const props = defineProps({ student: { type: Object, default: null } });

const form = useForm({
    name: props.student?.name ?? '',
    middle_name: props.student?.middle_name ?? '',
    last_name: props.student?.last_name ?? '',
    collage_name: props.student?.collage_name ?? '',
    canteen_id: props.student?.canteen_id ?? '',
    email: props.student?.email ?? '',
    phone_number: props.student?.phone_number ?? '',
    profile_pic: null,
});

// PHP cannot read a photo from a PUT, so changes go as a POST marked "put".
function save() {
    form.transform((data) => (props.student ? { ...data, _method: 'put' } : data))
        .post(props.student ? `/user/${props.student.id}` : '/user', { forceFormData: true, onError: focusFirstError });
}
</script>

<template>
    <AdminLayout :title="student ? `Edit ${displayName(student)}` : 'Add student'" :back="{ href: '/user', label: 'Back to students' }">
        <form class="panel formgrid" novalidate @submit.prevent="save">
            <FormField id="name" v-model="form.name" label="First name" :error="form.errors.name" autocomplete="off" maxlength="30" />
            <FormField id="middle_name" v-model="form.middle_name" label="Middle name (optional)" :error="form.errors.middle_name" autocomplete="off" maxlength="30" />
            <FormField id="last_name" v-model="form.last_name" label="Last name (optional)" :error="form.errors.last_name" autocomplete="off" maxlength="30" />
            <FormField id="collage_name" v-model="form.collage_name" label="College" :error="form.errors.collage_name" autocomplete="off" maxlength="30" />
            <FormField
                id="canteen_id" v-model="form.canteen_id" label="Canteen ID" :error="form.errors.canteen_id" autocomplete="off" maxlength="10"
                :readonly="!!student?.canteen_id" :help="student?.canteen_id ? 'This cannot be changed.' : 'The number on the student\'s card.'"
            />
            <FormField id="email" v-model="form.email" label="Email" type="email" :error="form.errors.email" autocomplete="off" />
            <FormField id="phone_number" v-model="form.phone_number" label="Phone number" type="tel" inputmode="numeric" :error="form.errors.phone_number" help="10 digits." autocomplete="off" />
            <PhotoField
                id="profile_pic" v-model="form.profile_pic" label="Photo (optional)" :error="form.errors.profile_pic"
                :current="student?.profile_pic ? `/image/profile/${encodeURIComponent(student.profile_pic)}` : null"
            />
            <div class="full acts start">
                <AppButton type="submit" :icon="Save" :disabled="form.processing">{{ student ? 'Save changes' : 'Save student' }}</AppButton>
                <AppButton variant="plain" href="/user">Cancel</AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
