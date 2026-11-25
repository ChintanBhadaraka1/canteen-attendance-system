<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Ban, Image, RefreshCw } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { shortDate } from '../../format';
import { rupees } from '../../stock';

const props = defineProps({
    purchase: { type: Object, required: true },
    rows: { type: Array, required: true },
    used: { type: Array, required: true },
});
const dialog = ref(null);
const form = useForm({ reason: '' });
const text = computed(() => 'The items are taken out of stock again.' + (props.used.length ? ` ${props.used.join(', ')} ${props.used.length === 1 && props.used[0].startsWith('1 ') ? 'was' : 'were'} already used. The recorded cost will not change.` : ''));
function ask() {
    form.reset();
    form.clearErrors();
    dialog.value.open();
}
function cancel() {
    if (form.processing) return;
    form.post(`/stock/entries/${props.purchase.id}/cancel`, { preserveScroll: true, onSuccess: () => dialog.value?.close(), onError: focusFirstError });
}
</script>

<template>
    <AdminLayout :title="`Purchase of ${shortDate(purchase.date)}`" :back="{ href: '/purchases', label: 'Back to purchases' }">
        <template #actions>
            <AppButton variant="plain" :icon="RefreshCw" :href="`/purchases/create?from=${purchase.id}`">Buy again</AppButton>
            <AppButton v-if="!purchase.cancelled" variant="danger" :icon="Ban" @click="ask">Cancel purchase</AppButton>
        </template>
        <p v-if="purchase.cancelled" class="panel gone"><StatusTag kind="due">Cancelled</StatusTag> {{ purchase.reason }} ({{ purchase.cancelled_by }})</p>
        <div class="two">
            <section class="panel">
                <h2>Items</h2>
                <ul class="rows">
                    <li v-for="row in rows" :key="row.id">
                        <span class="line"><b>{{ row.name }}</b><br>{{ row.entered }}<br><span class="small">{{ rupees(row.rate) }} per {{ row.unit }}</span></span>
                        <b>{{ rupees(row.cost) }}</b>
                    </li>
                </ul>
            </section>
            <section class="panel sum">
                <h2>Bill</h2>
                <div><span>Shop</span><span>{{ purchase.shop_name ?? 'No shop' }}</span></div>
                <div><span>Bill number</span><span class="line">{{ purchase.bill_number ?? 'None' }}</span></div>
                <div><span>Paid by</span><span>{{ purchase.method }}</span></div>
                <div><span>Items</span><span>{{ rupees(purchase.total + purchase.discount - purchase.charges) }}</span></div>
                <div><span>Discount</span><span>− {{ rupees(purchase.discount) }}</span></div>
                <div><span>Charges</span><span>+ {{ rupees(purchase.charges) }}</span></div>
                <div class="tot"><span>Total</span><span>{{ rupees(purchase.total) }}</span></div>
                <p v-if="purchase.note" class="line">{{ purchase.note }}</p>
                <p class="small">Saved by {{ purchase.user_name }}</p>
                <AppButton v-if="purchase.bill" variant="plain" :icon="Image" :href="`/purchases/${purchase.id}/bill`" external>Bill photo</AppButton>
            </section>
        </div>
        <ConfirmDialog ref="dialog" title="Cancel this purchase?" :text="text" confirm-label="Cancel purchase" cancel-label="Keep it" :icon="Ban" :busy="form.processing" @confirm="cancel">
            <FormField id="reason" v-model="form.reason" label="Why is it cancelled?" maxlength="200" autocomplete="off" required :error="form.errors.reason" @keydown.enter.prevent="cancel" />
        </ConfirmDialog>
    </AdminLayout>
</template>

<style scoped>
.gone { margin-bottom: 1.2rem; overflow-wrap: anywhere; }
.line { min-width: 0; overflow-wrap: anywhere; }
</style>
