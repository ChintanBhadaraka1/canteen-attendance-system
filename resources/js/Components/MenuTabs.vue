<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, Utensils } from '@lucide/vue';

const tabs = [
    { href: '/menu-plan', label: 'Week menu', icon: CalendarDays },
    { href: '/menus', label: 'Menu items', icon: Utensils },
];
const path = usePage().url.split('?')[0];
</script>

<template>
    <nav class="tabs" aria-label="Menu">
        <Link v-for="tab in tabs" :key="tab.href" :href="tab.href" :aria-current="path === tab.href ? 'page' : undefined">
            <component :is="tab.icon" aria-hidden="true" />{{ tab.label }}
        </Link>
    </nav>
</template>
