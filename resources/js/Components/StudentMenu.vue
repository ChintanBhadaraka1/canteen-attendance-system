<script setup>
import DayMenu from './DayMenu.vue';
import { money } from '../format';

defineProps({ meals: { type: Array, required: true } });
</script>

<template>
    <div class="menus">
        <div v-for="meal in meals" :key="meal.meal">
            <h3>{{ meal.meal }} {{ money(meal.price) }}</h3>
            <p v-if="meal.serve_from">{{ meal.serve_from }} to {{ meal.serve_to }}</p>
            <DayMenu :menu="meal" full />
        </div>
    </div>
</template>

<style scoped>
.menus { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr)); gap: 24px; }
.menus > div { border-top: 3px solid var(--color-turmeric); padding-top: 12px; min-width: 0; }
h3 { font-weight: 700; font-size: 1.3rem; overflow-wrap: anywhere; }
</style>
