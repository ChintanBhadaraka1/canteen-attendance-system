<script setup>
import { Coffee, Moon, Sun, Utensils } from '@lucide/vue';
import { money } from '../format';

defineProps({
    meals: { type: Array, required: true },
    legend: { type: String, required: true },
    error: { type: String, default: undefined },
});
const chosen = defineModel({ type: Number, default: null });
const icons = [Coffee, Sun, Moon];
</script>

<template>
    <fieldset class="meals">
        <legend>{{ legend }}</legend>
        <button
            v-for="(meal, index) in meals" :key="meal.id" type="button" :aria-pressed="chosen === meal.id"
            :aria-invalid="error ? 'true' : undefined" @click="chosen = meal.id"
        >
            <component :is="icons[index] ?? Utensils" aria-hidden="true" /><span>{{ meal.name }} <template v-if="meal.reason">Special price {{ money(meal.price) }} ({{ meal.reason }})</template><template v-else>{{ money(meal.price) }}</template></span>
        </button>
        <p v-if="!meals.length" class="full">No meals yet. Add one under Meal prices.</p>
        <p v-if="error" class="err full" role="alert">{{ error }}</p>
    </fieldset>
</template>
