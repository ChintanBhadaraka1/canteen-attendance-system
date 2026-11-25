<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Check, RefreshCw } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import CountButtons from '../../Components/CountButtons.vue';
import FormField from '../../Components/FormField.vue';
import MealChoice from '../../Components/MealChoice.vue';
import MealMenu from '../../Components/MealMenu.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { useDatePrices } from '../../datePrices';
import { focusFirstError } from '../../forms';
import { displayName, money } from '../../format';

const props = defineProps({
    student: { type: Object, required: true },
    meals: { type: Array, required: true },
    menu: { type: Object, required: true },
    date: { type: String, required: true },
    extras: { type: Array, required: true },
    today: { type: String, required: true },
});

const asked = Number(new URLSearchParams(usePage().url.split('?')[1]).get('meal'));

const form = useForm({
    user_id: props.student.id,
    date: props.date,
    meal_id: props.meals.some((meal) => meal.id === asked) ? asked : null,
    include_extra: 'no',
    extra_items: Object.fromEntries(props.extras.map((extra) => [extra.id, 0])),
});

const total = computed(() => {
    const meal = Number(props.meals.find(({ id }) => id === form.meal_id)?.price ?? 0);
    const extras = form.include_extra === 'yes'
        ? props.extras.reduce((sum, extra) => sum + form.extra_items[extra.id] * Number(extra.price), 0) : 0;
    return meal + extras;
});
const { loading, failed: loadFailed, load: loadPrices } = useDatePrices(form, props, props.today, ['meals', 'menu', 'date']);

function save() {
    form.clearErrors();
    if (form.meal_id === null) form.setError('meal_id', 'Choose a meal.');
    if (!form.date || form.date > props.today) form.setError('date', 'Choose today or an earlier date.');
    if (form.hasErrors) return focusFirstError();
    if (loading.value) return;
    form.post('/student-attendance/store', { onError: focusFirstError });
}
</script>

<template>
    <AdminLayout :title="`Mark attendance for ${displayName(student)}`" :back="{ href: '/student-attendance', label: 'Back to attendance' }">
        <form class="panel stack" novalidate @submit.prevent="save">
            <FormField id="date" v-model="form.date" label="Date" type="date" :max="today" :error="form.errors.date" />
            <MealChoice v-model="form.meal_id" :meals="meals" legend="Choose the meal" :error="form.errors.meal_id" />
            <MealMenu :meal="meals.find(({ id }) => id === form.meal_id)" :menu="menu" />
            <fieldset v-if="extras.length" class="seg">
                <legend>Add extra items?</legend>
                <button type="button" :aria-pressed="form.include_extra === 'no'" @click="form.include_extra = 'no'">No</button>
                <button type="button" :aria-pressed="form.include_extra === 'yes'" @click="form.include_extra = 'yes'">Yes</button>
            </fieldset>
            <ul v-if="form.include_extra === 'yes'" class="rows">
                <li v-for="extra in extras" :key="extra.id">
                    <span>{{ extra.name }} <span class="small">{{ money(extra.price) }} each</span></span>
                    <CountButtons v-model="form.extra_items[extra.id]" :name="extra.name" />
                </li>
            </ul>
            <p class="after" aria-live="polite">{{ loading ? 'Loading the price of this date…' : `Total: ${money(total)}` }}</p>
            <div v-if="loadFailed && loading" class="acts start">
                <p class="err" role="alert">The price could not be loaded.</p>
                <AppButton variant="plain" :icon="RefreshCw" @click="loadPrices">Try again</AppButton>
            </div>
            <div class="acts start">
                <AppButton type="submit" :icon="Check" :disabled="form.processing || loading">Save attendance</AppButton>
                <AppButton variant="plain" href="/student-attendance">Cancel</AppButton>
            </div>
        </form>
    </AdminLayout>
</template>

<style scoped>
.stack :deep(.meals), .stack :deep(.mealmenu) { margin-bottom: 0; }
</style>
