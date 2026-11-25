<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Check, RefreshCw } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import LeaveGuard from '../../Components/LeaveGuard.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { amountOf, parseQuantity } from '../../stock';

const props = defineProps({
    items: { type: Array, required: true },
    category: { type: Number, default: null },
    categories: { type: Array, required: true },
    token: { type: String, required: true },
});

const today = usePage().props.today;
const counts = ref({});
const form = useForm({ date: today, reason_text: '', note: '' });
let token = props.token;
watch(() => props.category, () => {
    counts.value = {};
    token = props.token;
});

const counted = computed(() => props.items.filter(({ id }) => String(counts.value[id] ?? '').trim() !== ''));
const difference = (item) => {
    const typed = parseQuantity(counts.value[item.id]);
    return typed === null ? null : typed - item.on_hand;
};
const changed = computed(() => counted.value.filter((item) => difference(item)));
const words = (item) => {
    const by = difference(item);
    if (by === null) return 'Enter a number, for example 2.5.';
    return by === 0 ? 'Matches' : `${amountOf(Math.abs(by), item.unit)} ${by > 0 ? 'more' : 'less'}`;
};

const dialog = ref(null);
const unanswered = ref(false);
const rowError = (item) => {
    const index = counted.value.indexOf(item);
    return index < 0 ? undefined : form.errors[`rows.${index}.quantity`] ?? form.errors[`rows.${index}.item`];
};
function ask() {
    form.clearErrors();
    counted.value.forEach((item, index) => { if (difference(item) === null) form.setError(`rows.${index}.quantity`, 'Enter a number, for example 2.5.'); });
    if (!counted.value.length) form.setError('rows', 'Count at least one item.');
    if (changed.value.length && !form.reason_text.trim()) form.setError('reason_text', 'Write why the count is different.');
    if (form.hasErrors) return focusFirstError();
    dialog.value.open();
}
function save() {
    if (form.processing) return;
    unanswered.value = false;
    form.transform((data) => ({
        ...data, token,
        rows: counted.value.map((item) => ({ item: item.id, quantity: String(counts.value[item.id]), unit: item.unit, expected: item.on_hand })),
    })).post('/stock/count', {
        preserveScroll: 'errors',
        onError: focusFirstError,
        onHttpException: () => { unanswered.value = true; return false; },
        onNetworkError: () => { unanswered.value = true; return false; },
        onFinish: () => dialog.value?.close(),
    });
}
const refresh = () => router.reload({ only: ['items'], onSuccess: () => form.clearErrors() });
const selectKey = ref(0);
</script>

<template>
    <AdminLayout title="Stock count" :back="{ href: '/stock', label: 'Back to stock' }">
        <div class="filters">
            <FormField id="category" :key="selectKey" as="select" label="Category" :model-value="category ?? ''" @update:model-value="(id) => router.get('/stock/count', id ? { category: id } : {}, { preserveState: true })">
                <option value="">All</option>
                <option v-for="one in categories" :key="one.id" :value="one.id">{{ one.name }}</option>
            </FormField>
        </div>
        <p v-if="!items.length" class="panel">No items here.</p>
        <form v-else class="stack" novalidate @submit.prevent="ask">
            <p>Write what you count. An empty box is not counted; 0 means nothing is left.</p>
            <ul class="panel rows">
                <li v-for="item in items" :key="item.id">
                    <span class="name"><b>{{ item.name }}</b><br><span class="small">{{ amountOf(item.on_hand, item.unit) }} in the app</span></span>
                    <span class="box">
                        <FormField :id="`count-${item.id}`" v-model="counts[item.id]" :label="`Counted (${item.unit})`" inputmode="decimal" maxlength="11" autocomplete="off" :aria-label="`Counted ${item.name} in ${item.unit}`" :error="rowError(item)" />
                        <span aria-live="polite"><b v-if="counted.includes(item)">{{ words(item) }}</b></span>
                    </span>
                </li>
            </ul>
            <section class="panel formgrid">
                <FormField id="date" v-model="form.date" label="Date" type="date" :max="today" :error="form.errors.date" />
                <FormField id="reason_text" v-model="form.reason_text" label="Why is it different?" maxlength="200" autocomplete="off" :error="form.errors.reason_text" />
                <FormField id="note" v-model="form.note" label="Note (optional)" maxlength="200" autocomplete="off" :error="form.errors.note" />
            </section>
            <p v-if="form.errors.rows || form.errors.token" class="err" role="alert">{{ form.errors.rows ?? 'Reload the page and try again.' }}</p>
            <div v-if="form.errors.stock" class="acts start">
                <p class="err" role="alert">{{ form.errors.stock }}</p>
                <AppButton variant="plain" :icon="RefreshCw" @click="refresh">Show the new stock</AppButton>
            </div>
            <p v-if="unanswered" class="err" role="alert">No answer from the server. Save again: it is never saved twice.</p>
            <div class="acts start"><AppButton type="submit" :icon="Check" :disabled="form.processing">Review and save</AppButton></div>
        </form>
        <ConfirmDialog
            ref="dialog" title="Save stock count?" :text="`${counted.length} counted, ${changed.length} different.`"
            confirm-label="Save" cancel-label="Go back" variant="primary" :icon="Check" :busy="form.processing" @confirm="save"
        >
            <ul class="rows list"><li v-for="item in changed" :key="item.id">{{ item.name }}<b>{{ words(item) }}</b></li></ul>
        </ConfirmDialog>
        <LeaveGuard :active="counted.length > 0" :text="`${counted.length} counted, not saved yet.`" saves="/stock" @stay="selectKey++" />
    </AdminLayout>
</template>

<style scoped>
.name { flex: 1 1 12rem; min-width: 0; overflow-wrap: anywhere; }
.box { display: flex; align-items: end; gap: 1rem; flex-wrap: wrap; flex: 0 1 24rem; }
.box > :deep(.field) { flex: 1 1 10rem; }
.box > span { min-height: 3.1rem; display: flex; align-items: center; flex: 1 1 8rem; }
.list { max-height: 40vh; overflow: auto; margin-bottom: 1.2rem; overflow-wrap: anywhere; }
</style>
