<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Check, CircleAlert, CircleCheck, RefreshCw, Undo2, UserPlus } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import AttendanceTabs from '../../Components/AttendanceTabs.vue';
import Avatar from '../../Components/Avatar.vue';
import FormField from '../../Components/FormField.vue';
import LeaveGuard from '../../Components/LeaveGuard.vue';
import MealChoice from '../../Components/MealChoice.vue';
import MealMenu from '../../Components/MealMenu.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';

const props = defineProps({
    meals: { type: Array, required: true },
    meal: { type: Number, default: null },
    menu: { type: Object, required: true },
    recent: { type: Array, required: true },
});

const page = usePage();
const typed = ref('');
const queue = ref([]);
const busy = ref(false);
const stopped = ref(false);
const result = ref(null);
const undoing = ref(false);
const waiting = computed(() => queue.value.length);

const focus = () => nextTick(() => document.getElementById('quick-id')?.focus());
onMounted(focus);

// A scanner can be faster than the server: keep every ID, send one at a time.
function add(text) {
    queue.value.push(...text.split(/[\r\n\t]+/).map((id) => id.trim()).filter(Boolean));
    typed.value = '';
    send();
}
function paste(event) {
    const text = event.clipboardData.getData('text');
    if (!/[\r\n\t]/.test(text.trim())) return;
    event.preventDefault();
    add(text);
}

function send() {
    if (busy.value || stopped.value || undoing.value || !queue.value.length || props.meal === null) return;
    const id = queue.value[0];
    let answered = false;
    router.post('/student-attendance/quick', { canteen_id: id, meal_id: props.meal }, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { busy.value = true; },
        onSuccess: ({ props: { flash } }) => {
            answered = true;
            result.value = flash.marked ? { ...flash.marked, ok: true } : null;
        },
        onError: (errors) => {
            answered = true;
            result.value = { ok: false, message: errors.canteen_id ?? errors.meal_id, student_id: page.props.flash.already };
        },
        onHttpException: () => false,
        onNetworkError: () => false,
        onFinish: () => {
            busy.value = false;
            if (answered) queue.value.shift();
            else stopped.value = true;
            send();
        },
    });
}
function again() {
    stopped.value = false;
    send();
    focus();
}

const undo = (entry) => router.post('/student-attendance/delete', { id: entry.id, return_to: 'quick', meal_id: props.meal }, {
    preserveState: true,
    preserveScroll: true,
    onStart: () => { undoing.value = true; },
    onSuccess: () => { if (result.value?.id === entry.id) result.value = null; },
    onFinish: () => {
        undoing.value = false;
        focus();
        send();
    },
});
const choose = (id) => router.get('/student-attendance/quick', { meal: id }, {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
        queue.value = [];
        stopped.value = false;
        result.value = null;
        focus();
    },
});
const off = computed(() => undoing.value || busy.value || waiting.value > 0);
</script>

<template>
    <AdminLayout title="Mark attendance">
        <AttendanceTabs :meal="meal" />
        <MealChoice :model-value="meal" :meals="meals" legend="1. Choose the meal" @update:model-value="choose" />
        <MealMenu :meal="meals.find(({ id }) => id === meal)" :menu="menu" />
        <p v-if="meal === null && meals.length" class="panel">Choose a meal first.</p>
        <template v-if="meal !== null">
            <form class="panel stack" novalidate @submit.prevent="add(typed)">
                <FormField id="quick-id" v-model="typed" class="big" label="2. Type the canteen ID and press Enter" autocomplete="off" autocapitalize="off" spellcheck="false" @paste="paste" />
                <AppButton type="submit" :icon="Check">Mark</AppButton>
                <p v-if="waiting" role="status">{{ waiting }} waiting</p>
                <div v-if="stopped" class="stack">
                    <p class="err" role="alert">No answer from the server. Nothing is lost: {{ queue.join(', ') }}</p>
                    <AppButton variant="plain" :icon="RefreshCw" @click="again">Try again</AppButton>
                </div>
            </form>
            <div aria-live="polite">
                <section v-if="result" class="panel outcome" :class="{ bad: !result.ok, away: result.away }" :role="result.ok ? 'status' : 'alert'">
                    <component :is="result.ok ? CircleCheck : CircleAlert" class="sign" aria-hidden="true" />
                    <Avatar v-if="result.ok" :name="result.name" :photo="result.photo" />
                    <p><b>{{ result.ok ? 'Marked' : 'Not marked' }}</b><br>{{ result.message }}</p>
                    <AppButton v-if="result.ok" variant="plain" :icon="Undo2" :disabled="off" @click="undo(result)">Undo<span class="sr-only"> {{ result.name }}</span></AppButton>
                    <AppButton v-if="result.student_id" variant="plain" :icon="UserPlus" :href="`/student-attendance/guest?type=card&student=${result.student_id}&meal=${meal}`">Add guest plate</AppButton>
                </section>
            </div>
            <section class="panel recent">
                <h2>Last entries today</h2>
                <p v-if="!recent.length">Nobody yet.</p>
                <ul class="rows">
                    <li v-for="entry in recent" :key="entry.id">
                        <span class="who"><Avatar :name="entry.name" :photo="entry.photo" /><span><b>{{ entry.name }}</b><br><span class="small">{{ entry.canteen_id }}</span></span></span>
                        <span v-if="entry.is_paid" class="small">Already paid</span>
                        <AppButton v-else variant="plain" :icon="Undo2" :disabled="off" @click="undo(entry)">Undo<span class="sr-only"> {{ entry.name }}</span></AppButton>
                    </li>
                </ul>
            </section>
        </template>
        <LeaveGuard :active="waiting > 0" :text="`${waiting} canteen IDs are waiting to be marked.`" />
    </AdminLayout>
</template>

<style scoped>
.big :deep(input) { font-size: 1.8rem; font-weight: 700; }
.outcome { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-top: 1.2rem; border: 3px solid var(--color-ok); background: var(--color-ok-bg); font-size: 1.35rem; }
.outcome p { flex: 1 1 12rem; min-width: 0; overflow-wrap: anywhere; }
.outcome .sign { width: 2.6rem; height: 2.6rem; color: var(--color-ok); }
.outcome :deep(.ava) { width: 5rem; height: 5rem; font-size: 2rem; }
.outcome.away { border-color: var(--color-due); background: var(--color-due-bg); }
.outcome.bad { border-color: var(--color-danger); background: #fdecec; }
.outcome.bad .sign { color: var(--color-danger); }
.recent { margin-top: 1.2rem; }
</style>
