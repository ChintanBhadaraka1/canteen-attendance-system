<script setup>
import { useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

const props = defineProps({
    item: { type: Object, required: true },
    categories: { type: Array, required: true },
});
const form = useForm({ name: props.item.name, category_id: props.item.category_id ?? '', unit: props.item.unit, low_level: props.item.low_level });
const save = () => form.put(`/stock/${props.item.id}`, { onError: focusFirstError });
</script>

<template>
    <AdminLayout :title="`Edit ${item.name}`" :back="{ href: `/stock/${item.id}`, label: 'Back to item' }">
        <form class="panel formgrid" novalidate @submit.prevent="save">
            <FormField id="name" v-model="form.name" label="Name" maxlength="60" autocomplete="off" :error="form.errors.name" />
            <FormField id="category_id" v-model="form.category_id" as="select" label="Category" :error="form.errors.category_id">
                <option value="">None</option>
                <option v-for="one in categories" :key="one.id" :value="one.id">{{ one.name }}</option>
            </FormField>
            <FormField id="unit" v-model="form.unit" as="select" label="Unit" :disabled="item.moved" :help="item.moved ? 'Cannot change: it already has stock entries.' : undefined" :error="form.errors.unit">
                <option value="kg">kg</option>
                <option value="litre">litre</option>
                <option value="piece">piece</option>
            </FormField>
            <FormField id="low_level" v-model="form.low_level" :label="`Low at (${form.unit}, optional)`" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors.low_level" />
            <div class="full acts start">
                <AppButton type="submit" :icon="Save" :disabled="form.processing">Save</AppButton>
                <AppButton variant="plain" :href="`/stock/${item.id}`">Cancel</AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
