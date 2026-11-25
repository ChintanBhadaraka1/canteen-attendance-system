<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { Ban, Check, CircleOff, Pencil, Plus } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DataList from '../../Components/DataList.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { shortDate } from '../../format';
import { amountOf, rupees } from '../../stock';

const props = defineProps({
    item: { type: Object, required: true },
    rows: { type: Object, required: true },
});
const columns = [
    { key: 'date', label: 'Date' },
    { key: 'label', label: 'What' },
    { key: 'quantity', label: 'Change' },
    { key: 'cost', label: 'Cost' },
    { key: 'after_quantity', label: 'Left' },
];
const signed = (row) => `${row.quantity > 0 ? '+' : '−'} ${amountOf(Math.abs(row.quantity), props.item.unit)}`;
const turn = (url, active) => router.patch(url, { active }, { preserveScroll: true });

const pack = useForm({ name: '', size: '' });
const addPack = () => pack.post(`/stock/${props.item.id}/packs`, { preserveScroll: true, onSuccess: () => pack.reset(), onError: focusFirstError });

const dialog = ref(null);
const chosen = ref(null);
const form = useForm({ reason: '' });
function ask(row) {
    chosen.value = row;
    form.reset();
    form.clearErrors();
    dialog.value.open();
}
function cancel() {
    if (form.processing) return;
    form.transform((data) => ({ ...data, item: props.item.id })).post(`/stock/entries/${chosen.value.entry_id}/cancel`, {
        preserveScroll: true, onSuccess: () => dialog.value?.close(), onError: focusFirstError,
    });
}
</script>

<template>
    <AdminLayout :title="item.name" :back="{ href: '/stock', label: 'Back to stock' }">
        <template #actions>
            <AppButton variant="plain" :icon="Pencil" :href="`/stock/${item.id}/edit`">Edit</AppButton>
            <AppButton variant="plain" :icon="item.active ? CircleOff : Check" @click="turn(`/stock/${item.id}/active`, !item.active)">{{ item.active ? 'Turn off' : 'Turn on' }}</AppButton>
        </template>
        <div class="two">
            <section class="panel sum">
                <h2>Now</h2>
                <div><span>In stock</span><span>{{ amountOf(item.on_hand, item.unit) }} <StatusTag v-if="item.low" kind="due">Low</StatusTag></span></div>
                <div><span>Value</span><span>{{ rupees(item.value) }}</span></div>
                <div><span>Average</span><span>{{ item.average === null ? 'None left' : `${rupees(item.average)} per ${item.unit}` }}</span></div>
                <div><span>Last bought at</span><span>{{ item.last === null ? 'Not bought yet' : `${rupees(item.last)} per ${item.unit}` }}</span></div>
                <div><span>Low at</span><span>{{ item.low_level === null ? 'Not set' : amountOf(item.low_level, item.unit) }}</span></div>
                <div><span>Category</span><span>{{ item.category ?? 'None' }}</span></div>
                <p v-if="!item.active" class="err">Turned off: it cannot be bought.</p>
            </section>
            <section class="panel">
                <h2>Packs</h2>
                <p v-if="!item.packs.length" class="small">None yet.</p>
                <ul class="rows">
                    <li v-for="one in item.packs" :key="one.id">
                        <span class="label-text">{{ one.label }} <StatusTag v-if="!one.active" kind="due">Off</StatusTag></span>
                        <AppButton variant="plain" :icon="one.active ? CircleOff : Check" @click="turn(`/stock/packs/${one.id}/active`, !one.active)">{{ one.active ? 'Turn off' : 'Turn on' }}<span class="sr-only"> {{ one.label }}</span></AppButton>
                    </li>
                </ul>
                <form class="filters add" novalidate @submit.prevent="addPack">
                    <FormField id="pack-name" v-model="pack.name" label="New pack, for example Bag" maxlength="20" autocomplete="off" :error="pack.errors.name" />
                    <FormField id="pack-size" v-model="pack.size" :label="`Size in ${item.unit}`" inputmode="decimal" maxlength="11" autocomplete="off" :error="pack.errors.size" />
                    <AppButton type="submit" :icon="Plus" :disabled="pack.processing">Add pack</AppButton>
                </form>
            </section>
        </div>
        <h2 class="history">History</h2>
        <DataList :columns="columns" :rows="rows" :filters="{}" noun="entry" plural="entries">
            <template #cell-date="{ row }">{{ shortDate(row.date) }}</template>
            <template #cell-label="{ row }">
                <b>{{ row.label }}</b> <StatusTag v-if="row.cancelled" kind="due">Cancelled</StatusTag><br>
                <span class="small">{{ [row.entered, row.shop_name, row.reason, row.note, `by ${row.user_name}`].filter(Boolean).join(', ') }}</span>
            </template>
            <template #cell-quantity="{ row }">{{ signed(row) }}</template>
            <template #cell-cost="{ row }">{{ rupees(row.cost) }}</template>
            <template #cell-after_quantity="{ row }"><b>{{ amountOf(row.after_quantity, item.unit) }}</b></template>
            <template #actions="{ row }">
                <AppButton v-if="row.type === 'purchase'" variant="plain" :href="`/purchases/${row.entry_id}`">Open<span class="sr-only"> purchase of {{ shortDate(row.date) }}</span></AppButton>
                <AppButton v-else-if="row.cancellable" variant="danger" :icon="Ban" @click="ask(row)">Cancel<span class="sr-only"> {{ row.label }} of {{ shortDate(row.date) }}</span></AppButton>
            </template>
        </DataList>
        <ConfirmDialog
            ref="dialog" :title="chosen ? `Cancel ${chosen.label.toLowerCase()} of ${shortDate(chosen.date)}?` : ''" :text="chosen ? `Everything saved together is cancelled: ${chosen.names}.` : ''"
            confirm-label="Cancel entry" cancel-label="Keep it" :icon="Ban" :busy="form.processing" @confirm="cancel"
        >
            <FormField id="reason" v-model="form.reason" label="Why is it cancelled?" maxlength="200" autocomplete="off" required :error="form.errors.reason" @keydown.enter.prevent="cancel" />
        </ConfirmDialog>
    </AdminLayout>
</template>

<style scoped>
.add { margin: 1rem 0 0; }
.history { margin: 1.6rem 0 .8rem; }
.label-text { min-width: 0; overflow-wrap: anywhere; }
</style>
