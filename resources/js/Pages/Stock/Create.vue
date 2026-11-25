<script setup>
import { computed, nextTick } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Plus, Save, Trash2 } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import FormField from '../../Components/FormField.vue';
import LeaveGuard from '../../Components/LeaveGuard.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

const props = defineProps({
    categories: { type: Array, required: true },
    token: { type: String, required: true },
});

const empty = () => ({ name: '', category_id: '', unit: 'kg', low_level: '', quantity: '', amount: '' });
const form = useForm({ rows: [empty()], token: props.token });
const filled = computed(() => form.rows.filter((row) => row.name || row.low_level || row.quantity || row.amount).length);

async function add() {
    form.rows.push(empty());
    await nextTick();
    document.getElementById(`name-${form.rows.length - 1}`)?.focus();
}
function remove(index) {
    form.rows.splice(index, 1);
    form.clearErrors();
}
const save = () => form.post('/stock', { preserveScroll: 'errors', onError: focusFirstError });
</script>

<template>
    <AdminLayout title="Add items" :back="{ href: '/stock', label: 'Back to stock' }">
        <form class="stack" novalidate @submit.prevent="save">
            <fieldset v-for="(row, index) in form.rows" :key="index" class="panel line">
                <legend class="sr-only">Row {{ index + 1 }}</legend>
                <FormField :id="`name-${index}`" v-model="row.name" :label="`${index + 1}. Name`" maxlength="60" autocomplete="off" :error="form.errors[`rows.${index}.name`]" />
                <FormField :id="`category-${index}`" v-model="row.category_id" as="select" label="Category" :error="form.errors[`rows.${index}.category_id`]">
                    <option value="">None</option>
                    <option v-for="one in categories" :key="one.id" :value="one.id">{{ one.name }}</option>
                </FormField>
                <FormField :id="`unit-${index}`" v-model="row.unit" as="select" label="Unit" :error="form.errors[`rows.${index}.unit`]">
                    <option value="kg">kg</option>
                    <option value="litre">litre</option>
                    <option value="piece">piece</option>
                </FormField>
                <FormField :id="`low-${index}`" v-model="row.low_level" :label="`Low at (${row.unit}, optional)`" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors[`rows.${index}.low_level`]" />
                <FormField :id="`quantity-${index}`" v-model="row.quantity" :label="`In stock now (${row.unit}, optional)`" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors[`rows.${index}.quantity`]" />
                <FormField :id="`amount-${index}`" v-model="row.amount" label="Its value (₹)" inputmode="decimal" maxlength="11" autocomplete="off" :error="form.errors[`rows.${index}.amount`]" />
                <AppButton variant="danger" :icon="Trash2" :disabled="form.rows.length === 1" @click="remove(index)">Remove<span class="sr-only"> row {{ index + 1 }}</span></AppButton>
            </fieldset>
            <p v-if="form.errors.rows || form.errors.stock || form.errors.token" class="err" role="alert">{{ form.errors.rows ?? form.errors.stock ?? 'Reload the page and try again.' }}</p>
            <div class="acts start">
                <AppButton variant="plain" :icon="Plus" :disabled="form.rows.length >= 50" @click="add">Add a row</AppButton>
                <AppButton type="submit" :icon="Save" :disabled="form.processing">Save items</AppButton>
            </div>
        </form>
        <LeaveGuard :active="filled > 0" :text="`${filled} ${filled === 1 ? 'row' : 'rows'}, not saved yet.`" saves="/stock" />
    </AdminLayout>
</template>

<style scoped>
.line { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: .8rem; align-items: start; min-width: 0; }
.line > .btn { align-self: end; justify-self: start; }
</style>
