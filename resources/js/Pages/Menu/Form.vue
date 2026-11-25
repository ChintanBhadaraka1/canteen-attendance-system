<script setup>
import { useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import PhotoField from '../../Components/PhotoField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

const props = defineProps({
    item: { type: Object, default: null },
    options: { type: Object, required: true },
});

const form = useForm({
    name: props.item?.name ?? '',
    is_extra: props.item?.is_extra ?? false,
    price: props.item?.price ?? '',
    food_type_id: props.item?.food_type_id ?? '',
    category_id: props.item?.category_id ?? '',
    description: props.item?.description ?? '',
    available: props.item?.available ?? true,
    images: null,
});

// The server reads a ticked box as "on" and an unticked one as missing.
function save() {
    form.transform(({ is_extra, price, ...data }) => ({
        ...data,
        price: is_extra || form.errors.price ? price : props.item?.price ?? '',
        ...(is_extra && { is_extra: 'on' }),
        ...(props.item && { _method: 'put' }),
    })).post(props.item ? `/menus/${props.item.id}` : '/menus', { forceFormData: true, onError: focusFirstError });
}
</script>

<template>
    <AdminLayout :title="item ? `Edit ${item.name}` : 'Add menu item'" :back="{ href: '/menus', label: 'Back to menu' }">
        <form class="panel formgrid" novalidate @submit.prevent="save">
            <FormField id="name" v-model="form.name" label="Name" :error="form.errors.name" autocomplete="off" maxlength="30" />
            <PhotoField
                id="images" v-model="form.images" label="Photo (optional)" :error="form.errors.images"
                :current="item?.images ? `/image/menus/${encodeURIComponent(item.images)}` : null"
            />
            <FormField id="food_type_id" v-model="form.food_type_id" as="select" label="Food type" :error="form.errors.food_type_id">
                <option value="">Not set</option>
                <option v-for="option in options.food_type" :key="option.id" :value="option.id">{{ option.name }}</option>
            </FormField>
            <FormField id="category_id" v-model="form.category_id" as="select" label="Category" :error="form.errors.category_id">
                <option value="">Not set</option>
                <option v-for="option in options.category" :key="option.id" :value="option.id">{{ option.name }}</option>
            </FormField>
            <div class="full"><FormField id="description" v-model="form.description" label="Description (optional)" maxlength="200" autocomplete="off" :error="form.errors.description" /></div>
            <label class="check full"><input v-model="form.available" type="checkbox"> Available</label>
            <label class="check full"><input v-model="form.is_extra" type="checkbox"> This is an extra item</label>
            <FormField
                v-if="form.is_extra || form.errors.price" id="price" v-model="form.price" label="Price (₹)" type="number" inputmode="numeric" min="0" max="100000" step="1"
                :error="form.errors.price" help="Whole rupees. Added to the student's bill when they take this item."
            />
            <div class="full acts start">
                <AppButton type="submit" :icon="Save" :disabled="form.processing">{{ item ? 'Save changes' : 'Save item' }}</AppButton>
                <AppButton variant="plain" href="/menus">Cancel</AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
