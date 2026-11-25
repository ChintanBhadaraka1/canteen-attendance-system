<script setup>
import { nextTick } from 'vue';
import { Plus, Trash2 } from '@lucide/vue';
import AppButton from './AppButton.vue';
import FormField from './FormField.vue';
import { amountOf, parseRupees, quantity, rowQuantity, rupees } from '../stock';

const props = defineProps({
    items: { type: Array, required: true },
    errors: { type: Object, required: true },
    // A purchase has amounts; use and waste show what is left.
    amounts: Boolean,
});
const rows = defineModel({ type: Array, required: true });
const emit = defineEmits(['edited']);

const itemOf = (row) => props.items.find(({ id }) => id === Number(row.item));
const choose = (row, id) => {
    row.item = id;
    row.unit = itemOf(row)?.units[0].key ?? '';
    emit('edited');
};
async function add() {
    rows.value.push({ item: '', quantity: '', unit: '', amount: '' });
    emit('edited');
    await nextTick();
    document.getElementById(`item-${rows.value.length - 1}`)?.focus();
}
async function remove(index) {
    rows.value.splice(index, 1);
    emit('edited');
    await nextTick();
    document.getElementById(`item-${Math.max(0, index - 1)}`)?.focus();
}
function rate(row) {
    const item = itemOf(row);
    const amount = parseRupees(row.amount);
    const many = rowQuantity(row, item);
    return many && amount !== null ? `${rupees(Math.round((amount * 1000) / many))} per ${item.unit}` : '';
}
</script>

<template>
    <div class="stack">
        <fieldset v-for="(row, index) in rows" :key="index" class="panel line" :class="{ amounts }">
            <legend class="sr-only">Row {{ index + 1 }}</legend>
            <FormField :id="`item-${index}`" as="select" :label="`${index + 1}. Item`" :model-value="row.item" :error="errors[`rows.${index}.item`]" @update:model-value="(id) => choose(row, id)">
                <option value="">Choose</option>
                <option v-for="item in items" :key="item.id" :value="item.id">{{ item.name }}{{ item.active ? '' : ' (off)' }}</option>
            </FormField>
            <FormField :id="`quantity-${index}`" v-model="row.quantity" label="Quantity" inputmode="decimal" autocomplete="off" maxlength="11" :error="errors[`rows.${index}.quantity`]" @input="emit('edited')" />
            <FormField :id="`unit-${index}`" v-model="row.unit" as="select" label="Unit or pack" :disabled="!itemOf(row)" :error="errors[`rows.${index}.unit`]" @change="emit('edited')">
                <option v-if="!itemOf(row)" value="">Choose the item</option>
                <option v-for="unit in itemOf(row)?.units ?? []" :key="unit.key" :value="unit.key">{{ unit.label }}</option>
            </FormField>
            <FormField v-if="amounts" :id="`amount-${index}`" v-model="row.amount" label="Amount paid (₹)" inputmode="decimal" autocomplete="off" maxlength="11" :error="errors[`rows.${index}.amount`]" @input="emit('edited')" />
            <p class="small about" aria-live="polite">
                <template v-if="itemOf(row)">{{ amounts ? rate(row) : `${amountOf(itemOf(row).on_hand, itemOf(row).unit)} in stock` }}<template v-if="rowQuantity(row, itemOf(row)) !== null && row.unit !== itemOf(row).unit">{{ ` = ${quantity(rowQuantity(row, itemOf(row)))} ${itemOf(row).unit}` }}</template></template>
            </p>
            <AppButton variant="danger" :icon="Trash2" :disabled="rows.length === 1" @click="remove(index)">Remove<span class="sr-only"> row {{ index + 1 }}</span></AppButton>
        </fieldset>
        <p v-if="errors.rows" class="err" role="alert">{{ errors.rows }}</p>
        <div><AppButton variant="plain" :icon="Plus" :disabled="rows.length >= 100" @click="add">Add a row</AppButton></div>
    </div>
</template>

<style scoped>
.line { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 10rem), 1fr)); gap: .8rem; align-items: start; min-width: 0; }
.about { align-self: center; }
.line > .btn { align-self: end; justify-self: start; }
</style>
