<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, CalendarPlus, Pencil, Plus, Save, Trash2 } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { money, shortDate } from '../../format';

defineProps({ meals: { type: Array, required: true } });

const dialog = ref(null);
const chosen = ref(null);
const busy = ref(false);
const during = (box) => ({
    preserveScroll: true,
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        box?.value?.close();
    },
});

function ask(meal) {
    chosen.value = meal;
    dialog.value.open();
}
const remove = () => router.delete(`/meal-price/${chosen.value.id}`, during(dialog));
const move = (meal, direction) => router.post(`/meal-price/${meal.id}/move`, { direction }, during());

const period = (row) => (row.from_date === row.to_date ? shortDate(row.from_date) : `${shortDate(row.from_date)} to ${shortDate(row.to_date)}`);
const specialDialog = ref(null);
const special = ref(null);
const form = useForm({ price: '', from_date: '', to_date: '', reason: '' });
function edit(meal, row = null) {
    chosen.value = meal;
    special.value = row;
    form.clearErrors();
    Object.assign(form, { price: row?.price ?? '', from_date: row?.from_date ?? '', to_date: row?.to_date ?? '', reason: row?.reason ?? '' });
    specialDialog.value.open();
}
const saveSpecial = () => form.submit(special.value ? 'put' : 'post', special.value ? `/special-price/${special.value.id}` : `/meal-price/${chosen.value.id}/special`, {
    preserveScroll: true, onSuccess: () => specialDialog.value.close(), onError: focusFirstError,
});
const removeDialog = ref(null);
function askSpecial(meal, row) {
    chosen.value = meal;
    special.value = row;
    removeDialog.value.open();
}
const removeSpecial = () => router.delete(`/special-price/${special.value.id}`, during(removeDialog));
</script>

<template>
    <AdminLayout title="Meal prices">
        <template #actions><AppButton :icon="Plus" href="/meal-price/create">Add meal</AppButton></template>
        <p v-if="!meals.length" class="panel">No meals yet. Add the first meal and its price.</p>
        <div class="stack">
            <section v-for="(meal, index) in meals" :key="meal.id" class="panel stack">
                <div class="top">
                    <div>
                        <h2>{{ meal.name }} <StatusTag v-if="!meal.active" kind="due">Off</StatusTag></h2>
                        <span class="price">{{ money(meal.price) }}</span>
                        <p class="small">Normal price{{ meal.serve_from ? `. Served ${meal.serve_from} to ${meal.serve_to}` : '' }}</p>
                    </div>
                    <div class="acts">
                        <AppButton variant="plain" :icon="ArrowUp" :disabled="busy || index === 0" @click="move(meal, 'up')">Up<span class="sr-only">: {{ meal.name }}</span></AppButton>
                        <AppButton variant="plain" :icon="ArrowDown" :disabled="busy || index === meals.length - 1" @click="move(meal, 'down')">Down<span class="sr-only">: {{ meal.name }}</span></AppButton>
                        <AppButton variant="plain" :icon="Pencil" :href="`/meal-price/${meal.id}/edit`">Change<span class="sr-only"> {{ meal.name }}</span></AppButton>
                        <AppButton variant="danger" :icon="Trash2" @click="ask(meal)">Delete<span class="sr-only"> {{ meal.name }}</span></AppButton>
                    </div>
                </div>
                <ul v-if="meal.specials.length" class="rows">
                    <li v-for="row in meal.specials" :key="row.id">
                        <span class="what"><b>{{ money(row.price) }}</b> {{ period(row) }} ({{ row.reason }})<br><span v-if="row.marked" class="small">{{ row.marked }} already marked</span></span>
                        <span class="acts start">
                            <AppButton variant="plain" :icon="Pencil" @click="edit(meal, row)">Change<span class="sr-only"> special price {{ row.reason }}</span></AppButton>
                            <AppButton variant="danger" :icon="Trash2" @click="askSpecial(meal, row)">Remove<span class="sr-only"> special price {{ row.reason }}</span></AppButton>
                        </span>
                    </li>
                </ul>
                <div><AppButton variant="plain" :icon="CalendarPlus" @click="edit(meal)">Add special price<span class="sr-only"> for {{ meal.name }}</span></AppButton></div>
            </section>
        </div>
        <ConfirmDialog
            ref="dialog" :title="chosen ? `Delete ${chosen.name}?` : ''" text="Its menus and special prices are deleted too."
            confirm-label="Delete meal" cancel-label="Keep meal" :icon="Trash2" :busy="busy" @confirm="remove"
        />
        <ConfirmDialog
            ref="specialDialog" :title="chosen ? `Special price for ${chosen.name}` : ''" text="Used for meals marked after you save. Meals already marked keep their price."
            confirm-label="Save" cancel-label="Cancel" variant="primary" :icon="Save" :busy="form.processing" @confirm="saveSpecial"
        >
            <form class="stack fields" novalidate @submit.prevent="saveSpecial">
                <FormField id="price" v-model="form.price" label="Price (₹)" type="number" inputmode="numeric" min="0" max="100000" step="1" :error="form.errors.price" />
                <FormField id="from_date" v-model="form.from_date" label="First date" type="date" :error="form.errors.from_date" />
                <FormField id="to_date" v-model="form.to_date" label="Last date" type="date" :error="form.errors.to_date" />
                <FormField id="reason" v-model="form.reason" label="Reason" maxlength="60" autocomplete="off" :error="form.errors.reason" />
                <button type="submit" class="sr-only" tabindex="-1">Save</button>
            </form>
        </ConfirmDialog>
        <ConfirmDialog
            ref="removeDialog" :title="special ? `Remove special price ${money(special.price)}?` : ''" text="Meals already marked keep their price."
            confirm-label="Remove" cancel-label="Keep it" :icon="Trash2" :busy="busy" @confirm="removeSpecial"
        />
    </AdminLayout>
</template>

<style scoped>
.top { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
.top > div { min-width: 0; }
.what { min-width: 0; overflow-wrap: anywhere; }
.fields { margin-bottom: 1.4rem; }
</style>
