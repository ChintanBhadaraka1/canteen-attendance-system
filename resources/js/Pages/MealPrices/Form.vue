<script setup>
import { useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

const props = defineProps({ meal: { type: Object, default: null } });

const form = useForm({ name: props.meal?.name ?? '', price: props.meal?.price ?? '' });

const save = () => form.submit(
    props.meal ? 'put' : 'post',
    props.meal ? `/meal-price/${props.meal.id}` : '/meal-price',
    { onError: focusFirstError },
);
</script>

<template>
    <AdminLayout :title="meal ? `Change ${meal.name}` : 'Add meal'" :back="{ href: '/meal-price', label: 'Back to meal prices' }">
        <form class="panel formgrid" novalidate @submit.prevent="save">
            <FormField id="name" v-model="form.name" label="Meal name" :error="form.errors.name" autocomplete="off" maxlength="30" />
            <FormField id="price" v-model="form.price" label="Price (₹)" type="number" inputmode="decimal" min="0" :error="form.errors.price" />
            <div class="full acts start">
                <AppButton type="submit" :icon="Save" :disabled="form.processing">{{ meal ? 'Save changes' : 'Save meal' }}</AppButton>
                <AppButton variant="plain" href="/meal-price">Cancel</AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
