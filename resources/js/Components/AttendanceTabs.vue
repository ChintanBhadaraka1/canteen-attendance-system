<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { Hash, ListChecks, User, UserPlus } from '@lucide/vue';

const props = defineProps({ meal: { type: Number, default: null } });

const tabs = [
    { href: '/student-attendance', label: 'One by one', icon: User },
    { href: '/student-attendance/bulk', label: 'Whole list', icon: ListChecks },
    { href: '/student-attendance/quick', label: 'By canteen ID', icon: Hash },
    { href: '/student-attendance/guest', label: 'Guest', icon: UserPlus },
];
const path = usePage().url.split('?')[0];
const link = (href) => href + (props.meal === null ? '' : `?meal=${props.meal}`);
</script>

<template>
    <nav class="tabs" aria-label="Ways to mark attendance">
        <Link v-for="tab in tabs" :key="tab.href" :href="link(tab.href)" :aria-current="path === tab.href ? 'page' : undefined">
            <component :is="tab.icon" aria-hidden="true" />{{ tab.label }}
        </Link>
    </nav>
</template>
