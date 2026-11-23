<script setup>
import { computed } from 'vue';
import { ClipboardCheck, Wallet } from '@lucide/vue';
import AppButton from '../Components/AppButton.vue';
import StatusTag from '../Components/StatusTag.vue';
import AdminLayout from '../Layouts/AdminLayout.vue';
import { money } from '../format';

const props = defineProps({
    meals: { type: Array, required: true },
    students: { type: Number, required: true },
    due: { type: Object, required: true },
    week: { type: Array, required: true },
    highest: { type: Array, required: true },
});

const count = (n) => `${n} ${n === 1 ? 'student' : 'students'}`;
const most = computed(() => Math.max(1, ...props.week.map((day) => day.total)));
const weekText = computed(() => 'Meals per day: ' + props.week.map((day) => `${day.label} ${day.total}`).join(', '));
</script>

<template>
    <AdminLayout title="Today at the canteen">
        <div class="coupon">
            <div class="stubs">
                <div v-for="meal in meals" :key="meal.id">
                    <h2>{{ meal.name }}</h2>
                    <b class="n">{{ meal.total }}</b>
                    <span>of {{ count(students) }}</span>
                    <AppButton :icon="ClipboardCheck" :href="`/student-attendance?meal=${meal.id}`">Mark {{ meal.name }}</AppButton>
                </div>
                <div v-if="!meals.length">
                    <h2>No meals yet</h2>
                    <span>Add a meal and its price first.</span>
                    <AppButton href="/meal-price/create">Add meal</AppButton>
                </div>
            </div>
            <div class="stub">
                <h2>{{ due.students ? 'Money to collect' : 'No money to collect' }}</h2>
                <template v-if="due.students">
                    <b class="n">{{ money(due.total) }}</b>
                    <span>from {{ count(due.students) }}</span>
                    <AppButton :icon="Wallet" href="/bill?show=due">Take payment</AppButton>
                </template>
            </div>
        </div>
        <div class="two">
            <section class="panel">
                <h2>Meals in the last 7 days</h2>
                <div class="bars" role="img" :aria-label="weekText">
                    <div v-for="day in week" :key="day.date">
                        {{ day.total }}<i :style="{ height: `${(day.total / most) * 70}%` }" />{{ day.label }}
                    </div>
                </div>
            </section>
            <section class="panel">
                <h2>Highest bills</h2>
                <ul v-if="highest.length" class="rows">
                    <li v-for="student in highest" :key="student.id">
                        <span>{{ student.name }}<br><span class="small">Canteen ID {{ student.canteen_id ?? 'not given' }}</span></span>
                        <StatusTag kind="due">{{ money(student.due) }} due</StatusTag>
                    </li>
                </ul>
                <p v-else>No money to collect</p>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
.coupon { display: grid; grid-template-columns: minmax(0, 3fr) minmax(0, 1.15fr); background: var(--color-paper); border-radius: 10px; margin-bottom: 1.5rem; }
.stubs { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); }
.stubs > div, .stub { padding: 1.3rem; display: flex; flex-direction: column; gap: .4rem; min-width: 0; }
.stubs > div + div { border-left: 1px solid #b3c6e8; }
.coupon .n { font-weight: 800; font-stretch: 75%; font-size: 3.2rem; line-height: 1; overflow-wrap: anywhere; }
.coupon .btn { margin-top: auto; padding-inline: .5rem; }
.stub { border-left: 3px dashed #56719f; background: var(--color-navy-900); color: #fff; border-radius: 0 10px 10px 0; }
.stub .btn { background: var(--color-turmeric); border-color: var(--color-turmeric); color: var(--color-navy-950); }
.stub span { color: var(--color-navy-text); }
.stub :focus-visible { outline-color: var(--color-turmeric); }
.bars { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: .4rem; height: 12rem; margin-top: 1rem; }
.bars div { display: flex; flex-direction: column; justify-content: end; align-items: center; height: 100%; font-stretch: 75%; font-variant-numeric: tabular-nums; }
.bars i { width: 100%; background: var(--color-primary); border-radius: 4px 4px 0 0; min-height: 2px; }
@container admin (max-width: 60rem) {
    .coupon, .stubs { grid-template-columns: minmax(0, 1fr); }
    .stubs > div + div { border-left: 0; border-top: 1px solid #b3c6e8; }
    .stub { border-left: 0; border-top: 3px dashed #56719f; border-radius: 0 0 10px 10px; }
}
</style>
