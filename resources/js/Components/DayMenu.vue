<script setup>
import { CircleAlert } from '@lucide/vue';
import { money } from '../format';

defineProps({
    menu: { type: Object, default: null },
    // Full: description and category too, for students.
    full: Boolean,
});
</script>

<template>
    <div class="daymenu">
        <p v-if="!menu" class="quiet">Not planned</p>
        <p v-else-if="menu.closed" class="closed"><CircleAlert aria-hidden="true" /><b>No meal</b></p>
        <ul v-else>
            <li v-for="(item, index) in menu.items" :key="index">
                <b>{{ item.name }}</b>
                <span v-if="item.food_type || (full && item.category)">{{ ` (${[item.food_type, full && item.category].filter(Boolean).join(', ')})` }}</span>
                <span v-if="item.extra_price !== null">{{ `, extra ${money(item.extra_price)}` }}</span>
                <span v-if="item.available === false">{{ ', not available' }}</span>
                <span v-if="full && item.description" class="line">{{ item.description }}</span>
            </li>
        </ul>
        <p v-if="menu?.note">{{ menu.note }}</p>
    </div>
</template>

<style scoped>
.daymenu { overflow-wrap: anywhere; min-width: 0; }
.daymenu li { padding: .1rem 0; }
.line { display: block; }
.closed { display: flex; align-items: center; gap: .5rem; }
.closed svg { width: 1.45rem; height: 1.45rem; flex: none; }
</style>
