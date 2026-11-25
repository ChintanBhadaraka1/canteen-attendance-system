<script setup>
import { useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

const props = defineProps({ meal: { type: Object, default: null } });

const form = useForm({
    name: props.meal?.name ?? '',
    price: props.meal?.price ?? '',
    serve_from: props.meal?.serve_from ?? '',
    serve_to: props.meal?.serve_to ?? '',
    active: props.meal?.active ?? true,
});

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
            <FormField id="price" v-model="form.price" label="Normal price (₹)" type="number" inputmode="numeric" min="0" max="100000" step="1" help="Whole rupees" :error="form.errors.price" />
            <FormField id="serve_from" v-model="form.serve_from" label="Served from (optional)" type="time" :error="form.errors.serve_from" />
            <FormField id="serve_to" v-model="form.serve_to" label="Served until (optional)" type="time" :error="form.errors.serve_to" />
            <label v-if="meal" class="check full"><input id="active" v-model="form.active" type="checkbox" :aria-invalid="form.errors.active ? 'true' : undefined"> Meal is on (it can be marked and planned)</label>
            <p v-if="form.errors.active" class="err full" role="alert">{{ form.errors.active }}</p>
            <div class="full acts start">
                <AppButton type="submit" :icon="Save" :disabled="form.processing">{{ meal ? 'Save changes' : 'Save meal' }}</AppButton>
                <AppButton variant="plain" href="/meal-price">Cancel</AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
