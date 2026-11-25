<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { BarChart3, Coins, FileText, ShoppingCart, Utensils, Wallet } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';

const page = usePage();
const cards = computed(() => [
    { href: '/reports/summary', title: 'Month summary', text: 'What came in, what went out and the estimated profit.', icon: BarChart3 },
    { href: '/reports/due', title: 'Money due', text: 'Every student who owes money.', icon: Wallet },
    { href: '/reports/meals', title: 'Meals and waste', text: 'Meals by day, meal and college; extras and waste.', icon: Utensils },
    { href: '/reports/statements', title: 'Monthly bills', text: 'Bills of a month, one by one or all in one file.', icon: FileText },
    { href: '/purchases/report', title: 'Purchase report', text: 'What was bought, used and wasted.', icon: ShoppingCart },
    ...(page.props.modules?.cash ? [{ href: '/cash-check', title: 'Cash check', text: 'Compare the cash box with the app.', icon: Coins }] : []),
]);
</script>

<template>
    <AdminLayout title="Reports">
        <ul class="grid">
            <li v-for="card in cards" :key="card.href" class="panel">
                <h2>{{ card.title }}</h2>
                <p>{{ card.text }}</p>
                <AppButton variant="plain" :icon="card.icon" :href="card.href">Open<span class="sr-only"> {{ card.title }}</span></AppButton>
            </li>
        </ul>
    </AdminLayout>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 18rem), 1fr)); gap: 1.2rem; list-style: none; padding: 0; }
.grid li { display: flex; flex-direction: column; gap: .6rem; align-items: flex-start; }
.grid .btn { margin-top: auto; }
</style>
