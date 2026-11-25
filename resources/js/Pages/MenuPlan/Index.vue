<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, Calendar, Copy, Pencil } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DayMenu from '../../Components/DayMenu.vue';
import MenuTabs from '../../Components/MenuTabs.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { longDate, money, shortDate, weekday } from '../../format';

const props = defineProps({
    week: { type: String, required: true },
    previous: { type: String, required: true },
    next: { type: String, required: true },
    meals: { type: Array, required: true },
    menus: { type: Object, required: true },
    prices: { type: Object, required: true },
});

const today = usePage().props.today;
const days = computed(() => Object.keys(props.menus));
const title = computed(() => `${shortDate(days.value[0])} to ${shortDate(days.value[6])}`);
const repeat = (date, cell) => (cell.repeat === 'once' ? 'Only this date' : `Every ${weekday(date)}`);

const dialog = ref(null);
const busy = ref(false);
const copy = () => router.post('/menu-plan/copy', { week: props.week }, {
    preserveScroll: true,
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        dialog.value?.close();
    },
});
</script>

<template>
    <AdminLayout title="Menu">
        <MenuTabs />
        <div class="acts start weeks">
            <AppButton variant="plain" :icon="ArrowLeft" :href="`/menu-plan?week=${previous}`">Previous</AppButton>
            <AppButton variant="plain" :icon="Calendar" href="/menu-plan">This week</AppButton>
            <AppButton variant="plain" :icon="ArrowRight" :href="`/menu-plan?week=${next}`">Next</AppButton>
            <AppButton v-if="days[6] >= today" variant="plain" :icon="Copy" @click="dialog.open()">Copy last week</AppButton>
        </div>
        <h2 class="weektitle">{{ title }}</h2>
        <p v-if="!meals.length" class="panel">No meals yet. Add one under Meal prices.</p>
        <div v-else class="days">
            <section v-for="date in days" :key="date" class="panel day" :class="{ past: date < today, now: date === today }">
                <h3>{{ longDate(date) }}<template v-if="date === today"> (today)</template></h3>
                <div class="cells">
                    <div v-for="meal in meals" :key="meal.id" class="cell">
                        <h4>{{ meal.name }}</h4>
                        <DayMenu :menu="menus[date][meal.id]" />
                        <p v-if="menus[date][meal.id]" class="small">{{ repeat(date, menus[date][meal.id]) }}</p>
                        <p v-if="prices[date][meal.id].reason"><span class="tag due">Special price {{ money(prices[date][meal.id].price) }} ({{ prices[date][meal.id].reason }})</span></p>
                        <AppButton v-if="date >= today" variant="plain" :icon="Pencil" :href="`/menu-plan/edit?meal=${meal.id}&date=${date}`">Change<span class="sr-only"> {{ meal.name }} on {{ longDate(date) }}</span></AppButton>
                    </div>
                </div>
            </section>
        </div>
        <ConfirmDialog
            ref="dialog" title="Copy last week?" :text="`The menu shown in the week before is copied to ${title}. Days already set for one date are kept.`"
            confirm-label="Copy" cancel-label="Cancel" variant="primary" :icon="Copy" :busy="busy" @confirm="copy"
        />
    </AdminLayout>
</template>

<style scoped>
.weeks, .weektitle { margin-bottom: 1rem; }
.days { display: grid; gap: 1rem; }
.day { display: grid; grid-template-columns: 11rem minmax(0, 1fr); gap: 1rem; }
.day.now { border: 3px solid var(--color-primary); }
.day.past { background: var(--color-canvas); }
.day h3 { font-weight: 700; font-size: 1.15rem; }
.day h4 { font-weight: 700; }
.cells { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: 1rem; min-width: 0; }
.cell { display: flex; flex-direction: column; gap: .4rem; align-items: flex-start; min-width: 0; }
.cell .tag { white-space: normal; overflow-wrap: anywhere; }
.cell .btn { margin-top: auto; }
@container admin (max-width: 55.5rem) {
    .day { grid-template-columns: minmax(0, 1fr); }
    /* Past days hold no controls, so the keyboard order stays the same. */
    .day.past { order: 1; }
}
</style>
