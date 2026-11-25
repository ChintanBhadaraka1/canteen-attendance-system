<script setup>
import DayMenu from './DayMenu.vue';

defineProps({
    meal: { type: Object, default: undefined },
    menu: { type: Object, required: true },
});
</script>

<template>
    <section v-if="meal" class="panel mealmenu" :class="{ warn: menu[meal.id]?.closed }" :aria-label="`Menu of ${meal.name}`" aria-live="polite">
        <h2>Menu of {{ meal.name }}</h2>
        <DayMenu :menu="menu[meal.id]" />
        <p v-if="menu[meal.id]?.closed">You can still mark attendance.</p>
    </section>
</template>

<style scoped>
.mealmenu { margin-bottom: 1.2rem; }
.warn { background: var(--color-due-bg); border: 2px solid var(--color-due); color: var(--color-due); }
</style>
