<script setup>
import { Plus, UserRound } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { rupees } from '../../stock';

defineProps({ staff: { type: Array, required: true } });
</script>

<template>
    <AdminLayout title="Staff">
        <template #actions><AppButton :icon="Plus" href="/staff/create">Add staff</AppButton></template>
        <p v-if="!staff.length" class="panel">No staff yet.</p>
        <ul class="grid">
            <li v-for="one in staff" :key="one.id" class="panel sum">
                <h2 class="word">{{ one.name }}</h2>
                <p><StatusTag :kind="one.working ? 'ok' : 'due'">{{ one.working ? 'Working' : 'Left' }}</StatusTag></p>
                <div><span>Job</span><span class="word">{{ one.job ?? 'Not given' }}</span></div>
                <div><span>Salary</span><span>{{ rupees(one.salary) }}</span></div>
                <div><span>Left to pay this month</span><span>{{ rupees(one.left) }}</span></div>
                <AppButton variant="plain" :icon="UserRound" :href="`/staff/${one.id}`">Open<span class="sr-only"> {{ one.name }}</span></AppButton>
            </li>
        </ul>
    </AdminLayout>
</template>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 19rem), 1fr)); gap: 1.2rem; list-style: none; padding: 0; }
.word { min-width: 0; overflow-wrap: anywhere; }
.grid .btn { margin-top: .8rem; }
</style>
