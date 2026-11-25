<script setup>
import { computed } from 'vue';
import { CalendarDays, ClipboardCheck, Coins, Package, Wallet } from '@lucide/vue';
import AppButton from '../Components/AppButton.vue';
import DayMenu from '../Components/DayMenu.vue';
import StatusTag from '../Components/StatusTag.vue';
import AdminLayout from '../Layouts/AdminLayout.vue';
import { money } from '../format';
import { amountOf } from '../stock';

const props = defineProps({
    meals: { type: Array, required: true },
    menu: { type: Object, required: true },
    walkIn: { type: Object, required: true },
    low: { type: Object, required: true },
    students: { type: Number, required: true },
    due: { type: Object, required: true },
    week: { type: Array, required: true },
    highest: { type: Array, required: true },
});

const count = (n) => `${n} ${n === 1 ? 'student' : 'students'}`;
const guests = (n) => `${n} ${n === 1 ? 'guest' : 'guests'}`;
const most = computed(() => Math.max(1, ...props.week.map((day) => day.total + day.guests)));
const weekText = computed(() => 'Meals per day: ' + props.week.map((day) => `${day.label} ${day.total}${day.guests ? ` and ${guests(day.guests)}` : ''}`).join(', '));
</script>

<template>
    <AdminLayout title="Today at the canteen">
        <template v-if="$page.props.modules?.cash" #actions><AppButton variant="plain" :icon="Coins" href="/cash-check">Cash check</AppButton></template>
        <div class="coupon">
            <div class="stubs">
                <div v-for="meal in meals" :key="meal.id">
                    <h2>{{ meal.name }}</h2>
                    <b class="n">{{ meal.total }}</b>
                    <b v-if="meal.guests">+ {{ guests(meal.guests) }}</b>
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
        <section v-if="meals.length" class="panel today">
            <h2>Today's menu</h2>
            <div class="cards">
                <div v-for="meal in meals" :key="meal.id">
                    <h3>{{ meal.name }}</h3>
                    <DayMenu :menu="menu[meal.id]" />
                    <AppButton v-if="!menu[meal.id]" variant="plain" :icon="CalendarDays" :href="`/menu-plan/edit?meal=${meal.id}&date=${$page.props.today}`">Plan<span class="sr-only"> {{ meal.name }}</span></AppButton>
                </div>
            </div>
        </section>
        <section v-if="low.total" class="panel today">
            <h2>Low stock</h2>
            <ul class="rows">
                <li v-for="item in low.items" :key="item.id"><span class="word">{{ item.name }}</span><StatusTag kind="due">{{ amountOf(item.on_hand, item.unit) }} left</StatusTag></li>
            </ul>
            <AppButton variant="plain" :icon="Package" href="/stock?show=low">{{ low.total === 1 ? 'See the low item' : `See all ${low.total} low items` }}</AppButton>
        </section>
        <div class="two">
            <section class="panel">
                <h2>Meals in the last 7 days</h2>
                <div class="bars" role="img" :aria-label="weekText">
                    <div v-for="day in week" :key="day.date">
                        {{ day.total }}<span v-if="day.guests">+ {{ day.guests }}</span><i v-if="day.guests" class="g" :style="{ height: `${(day.guests / most) * 55}%` }" /><i :style="{ height: `${(day.total / most) * 55}%` }" />{{ day.label }}
                    </div>
                </div>
                <p v-if="week.some((day) => day.guests)" class="key"><i aria-hidden="true" />Guests</p>
                <div v-if="walkIn.cash || walkIn.upi" class="sum walkin">
                    <h2>Walk-in money today</h2>
                    <div><span>Cash</span><span>{{ money(walkIn.cash) }}</span></div>
                    <div><span>UPI</span><span>{{ money(walkIn.upi) }}</span></div>
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
.today { margin-bottom: 1.5rem; }
.word { min-width: 0; overflow-wrap: anywhere; }
.today h2 { margin-bottom: .7rem; }
.today h3 { font-weight: 700; }
.today .btn { margin-top: .4rem; }
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
.bars .g, .key i { background: var(--color-turmeric); }
.bars .g { border-bottom: 2px solid #fff; }
.bars .g + i { border-radius: 0; }
.key { display: flex; align-items: center; gap: .5rem; margin-top: .6rem; }
.key i { width: 1.1rem; height: 1.1rem; border-radius: 3px; }
.walkin { margin-top: 1.2rem; }
@container admin (max-width: 60rem) {
    .coupon, .stubs { grid-template-columns: minmax(0, 1fr); }
    .stubs > div + div { border-left: 0; border-top: 1px solid #b3c6e8; }
    .stub { border-left: 0; border-top: 3px dashed #56719f; border-radius: 0 0 10px 10px; }
}
</style>
