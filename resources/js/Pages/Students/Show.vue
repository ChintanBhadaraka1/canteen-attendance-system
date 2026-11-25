<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { CalendarPlus, ClipboardCheck, History, Pencil, Trash2, UserCheck, UserPlus, UserX, Wallet } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import Avatar from '../../Components/Avatar.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import MonthlyBill from '../../Components/MonthlyBill.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { displayName, money, shortDate } from '../../format';

const props = defineProps({
    student: { type: Object, required: true },
    meals: { type: Array, required: true },
    guests: { type: Number, required: true },
    bill: { type: Object, default: null },
    leaves: { type: Array, required: true },
    today: { type: String, required: true },
});

const name = computed(() => displayName(props.student));
const due = computed(() => (props.bill ? props.bill.amount + props.bill.pending_amount - props.bill.advance_amount : 0));
const busy = ref(false);
const during = (dialog) => ({
    preserveScroll: true,
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        dialog?.value?.close();
    },
});

const leftDialog = ref(null);
const setStatus = (status) => router.patch(`/user/${props.student.id}/status`, { status }, during(leftDialog));

const form = useForm({ from_date: props.today, to_date: props.today, reason: '' });
const addLeave = () => form.post(`/user/${props.student.id}/leave`, { preserveScroll: true, onSuccess: () => form.reset(), onError: focusFirstError });

const leaveDialog = ref(null);
const chosen = ref(null);
const period = (leave) => (leave.from_date === leave.to_date ? shortDate(leave.from_date) : `${shortDate(leave.from_date)} to ${shortDate(leave.to_date)}`);
function askLeave(leave) {
    chosen.value = leave;
    leaveDialog.value.open();
}
const removeLeave = () => router.delete(`/user/leave/${chosen.value.id}`, during(leaveDialog));
</script>

<template>
    <AdminLayout :title="displayName(student)" :back="{ href: '/user', label: 'Back to students' }">
        <div class="acts start actions">
            <AppButton v-if="!student.left_on" :icon="ClipboardCheck" :href="`/student-attendance/create/${student.id}`">Mark attendance</AppButton>
            <AppButton v-if="!student.left_on" variant="plain" :icon="UserPlus" :href="`/student-attendance/guest?type=card&student=${student.id}`">Add guest</AppButton>
            <AppButton variant="plain" :icon="History" :href="`/student-attendance/show/${student.id}`">History</AppButton>
            <AppButton v-if="bill" variant="plain" :icon="Wallet" :href="`/bill/${student.id}/edit`">Bill</AppButton>
            <AppButton variant="plain" :icon="Pencil" :href="`/user/${student.id}/edit`">Edit</AppButton>
            <AppButton v-if="student.left_on" variant="plain" :icon="UserCheck" :disabled="busy" @click="setStatus('active')">Mark as active</AppButton>
            <AppButton v-else variant="danger" :icon="UserX" @click="leftDialog.open()">Mark as left</AppButton>
        </div>
        <div class="two">
            <section class="panel stack">
                <div class="who"><Avatar :name="student.name" :photo="student.profile_pic" /><h2>Details</h2><StatusTag v-if="student.left_on" kind="due">Left on {{ shortDate(student.left_on) }}</StatusTag></div>
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
                        <div v-if="guests"><span>Guest plates</span><span>{{ guests }}</span></div>
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
                <MonthlyBill :student="student.id" />
                <section class="panel">
                    <h2>Leave</h2>
                    <ul class="rows">
                        <li v-for="leave in leaves" :key="leave.id">
                            <span class="period">{{ period(leave) }}<template v-if="leave.reason"><br><span class="small">{{ leave.reason }}</span></template></span>
                            <AppButton variant="danger" :icon="Trash2" @click="askLeave(leave)">Delete<span class="sr-only"> leave {{ period(leave) }}</span></AppButton>
                        </li>
                    </ul>
                    <form class="stack" novalidate @submit.prevent="addLeave">
                        <FormField id="from_date" v-model="form.from_date" label="Away from" type="date" :error="form.errors.from_date" />
                        <FormField id="to_date" v-model="form.to_date" label="Away until" type="date" :error="form.errors.to_date" />
                        <FormField id="reason" v-model="form.reason" label="Reason (optional)" maxlength="100" autocomplete="off" :error="form.errors.reason" />
                        <AppButton type="submit" variant="plain" :icon="CalendarPlus" :disabled="form.processing">Add leave</AppButton>
                    </form>
                </section>
            </div>
        </div>
        <ConfirmDialog
            ref="leftDialog" :title="`Mark ${name} as left?`"
            :text="`They can no longer be marked for meals. Bills and history stay.${due > 0 ? ` ${money(due)} is still due.` : ''}`"
            confirm-label="Mark as left" cancel-label="Keep active" :icon="UserX" :busy="busy" @confirm="setStatus('left')"
        />
        <ConfirmDialog
            ref="leaveDialog" :title="chosen ? `Delete leave ${period(chosen)}?` : ''" text="This cannot be undone."
            confirm-label="Delete leave" cancel-label="Keep leave" :icon="Trash2" :busy="busy" @confirm="removeLeave"
        />
    </AdminLayout>
</template>

<style scoped>
.actions { margin-bottom: 1.2rem; }
.who { flex-wrap: wrap; }
.who :deep(.tag) { white-space: normal; }
.period { min-width: 0; overflow-wrap: anywhere; }
.value { overflow-wrap: anywhere; text-align: right; }
</style>
