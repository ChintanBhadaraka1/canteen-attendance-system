<script setup>
import { ClipboardCheck, History, Pencil, Wallet } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import Avatar from '../../Components/Avatar.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { displayName, money } from '../../format';

defineProps({
    student: { type: Object, required: true },
    meals: { type: Array, required: true },
    bill: { type: Object, default: null },
});
</script>

<template>
    <AdminLayout :title="displayName(student)" :back="{ href: '/user', label: 'Back to students' }">
        <div class="acts start actions">
            <AppButton :icon="ClipboardCheck" :href="`/student-attendance/create/${student.id}`">Mark attendance</AppButton>
            <AppButton variant="plain" :icon="History" :href="`/student-attendance/show/${student.id}`">History</AppButton>
            <AppButton v-if="bill" variant="plain" :icon="Wallet" :href="`/bill/${student.id}/edit`">Bill</AppButton>
            <AppButton variant="plain" :icon="Pencil" :href="`/user/${student.id}/edit`">Edit</AppButton>
        </div>
        <div class="two">
            <section class="panel stack">
                <div class="who"><Avatar :name="student.name" :photo="student.profile_pic" /><h2>Details</h2></div>
                <div class="sum">
                    <div><span>Canteen ID</span><span>{{ student.canteen_id ?? 'Not given' }}</span></div>
                    <div><span>College</span><span>{{ student.collage_name ?? 'Not given' }}</span></div>
                    <div><span>Email</span><span class="value">{{ student.email }}</span></div>
                    <div><span>Phone number</span><span>{{ student.phone_number ?? 'Not given' }}</span></div>
                </div>
            </section>
            <div class="stack">
                <section class="panel">
                    <h2>Meals this month</h2>
                    <div class="sum">
                        <div v-for="meal in meals" :key="meal.id"><span>{{ meal.name }}</span><span>{{ meal.total }}</span></div>
                        <p v-if="!meals.length">No meals yet.</p>
                    </div>
                </section>
                <section v-if="bill" class="panel">
                    <h2>The bill</h2>
                    <div class="sum">
                        <div><span>Meals</span><span>{{ money(bill.amount) }}</span></div>
                        <div><span>Pending from before</span><span>{{ money(bill.pending_amount) }}</span></div>
                        <div><span>Advance paid</span><span>− {{ money(bill.advance_amount) }}</span></div>
                    </div>
                </section>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.actions { margin-bottom: 1.2rem; }
.value { overflow-wrap: anywhere; text-align: right; }
</style>
