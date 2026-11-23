<script setup>
import { useForm } from '@inertiajs/vue3';
import { Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import PhotoField from '../../Components/PhotoField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

const props = defineProps({ item: { type: Object, default: null } });

const form = useForm({
    name: props.item?.name ?? '',
    is_extra: props.item?.is_extra ?? false,
    price: props.item?.price ?? '',
    images: null,
});

// The server reads a ticked box as "on" and an unticked one as missing.
function save() {
    form.transform(({ is_extra, price, ...data }) => ({
        ...data,
        price: is_extra ? price : props.item?.price ?? '',
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
            <label class="check full"><input v-model="form.is_extra" type="checkbox"> This is an extra item</label>
            <FormField
                v-if="form.is_extra" id="price" v-model="form.price" label="Price (₹)" type="number" inputmode="decimal" min="0"
                :error="form.errors.price" help="Added to the student's bill when they take this item."
            />
            <div class="full acts start">
                <AppButton type="submit" :icon="Save" :disabled="form.processing">{{ item ? 'Save changes' : 'Save item' }}</AppButton>
                <AppButton variant="plain" href="/menus">Cancel</AppButton>
            </div>
        </form>
    </AdminLayout>
</template>

<style scoped>
.check { display: flex; align-items: center; gap: .8rem; min-height: 3.1rem; font-weight: 600; cursor: pointer; }
.check input { width: 1.6rem; height: 1.6rem; accent-color: var(--color-primary); flex: none; }
</style>
