<script setup>
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { Check, CheckCheck, History, Square, Undo2 } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import AttendanceTabs from '../../Components/AttendanceTabs.vue';
import Avatar from '../../Components/Avatar.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import LeaveGuard from '../../Components/LeaveGuard.vue';
import MealChoice from '../../Components/MealChoice.vue';
import MealMenu from '../../Components/MealMenu.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { displayName, money, shortDate } from '../../format';

const props = defineProps({
    meals: { type: Array, required: true },
    meal: { type: Number, default: null },
    menu: { type: Object, required: true },
    date: { type: String, required: true },
    students: { type: Array, required: true },
    last: { type: Array, required: true },
    batches: { type: Array, required: true },
});

const page = usePage();
const chosenMeal = computed(() => props.meals.find(({ id }) => id === props.meal));
const price = computed(() => Number(chosenMeal.value?.price ?? 0));

const search = ref('');
const college = ref('');
const colleges = computed(() => [...new Set(props.students.map((student) => student.collage_name).filter(Boolean))].sort());
const texts = computed(() => new Map(props.students.map((student) => [student.id, `${displayName(student)} ${student.canteen_id ?? ''} ${student.collage_name ?? ''}`.toLowerCase()])));
const shown = computed(() => {
    const word = search.value.trim().toLowerCase();
    return props.students.filter((student) => (!college.value || student.collage_name === college.value) && (!word || texts.value.get(student.id).includes(word)));
});

const locked = (student) => student.marked || student.paid_block;
const ticked = ref(new Set());
// A student marked or removed meanwhile does not stay in the count.
const chosen = computed(() => props.students.filter((student) => ticked.value.has(student.id) && !locked(student)).map(({ id }) => id));
const hidden = computed(() => {
    const ids = new Set(shown.value.map(({ id }) => id));
    return chosen.value.filter((id) => !ids.has(id)).length;
});
const total = computed(() => chosen.value.length * price.value);

function set(ids, on) {
    const next = new Set(ticked.value);
    ids.forEach((id) => (on ? next.add(id) : next.delete(id)));
    ticked.value = next;
}
const tickShown = (on) => set(shown.value.filter((student) => !locked(student) && !(on && student.away)).map(({ id }) => id), on);
function likeLastTime() {
    const last = new Set(props.last);
    set(props.students.filter((student) => last.has(student.id) && !locked(student) && !student.away).map(({ id }) => id), true);
}

const dateKey = ref(0);
const go = (changes) => router.get('/student-attendance/bulk', { date: props.date, meal: props.meal ?? undefined, ...changes }, { preserveState: true, preserveScroll: true });
watch(() => [props.date, props.meal], () => {
    ticked.value = new Set();
    skipped.value = [];
    saved.value = null;
});

const review = ref(null);
const busy = ref(false);
const failed = ref('');
const skipped = ref([]);
const saved = ref(null);

const reviewText = computed(() => `${chosen.value.length} × ${money(price.value)} = ${money(total.value)}, for ${shortDate(props.date)}.`
    + (chosenMeal.value?.reason ? ` Special price (${chosenMeal.value.reason}).`
        : props.date === page.props.today ? '' : ` Today's normal price of ${money(price.value)} will be charged.`));

function save() {
    if (busy.value) return;
    failed.value = '';
    let answered = false;
    router.post('/student-attendance/bulk', { students: chosen.value, meal_id: props.meal, date: props.date, price: price.value }, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => { busy.value = true; },
        onSuccess: ({ props: { flash } }) => {
            answered = true;
            // Without a result nothing was saved, so the ticks stay.
            if (!flash.bulk) return;
            ticked.value = new Set();
            skipped.value = flash.bulk.skipped;
            saved.value = flash.bulk.batch;
            window.scrollTo(0, 0);
        },
        onError: (errors) => {
            answered = true;
            failed.value = Object.values(errors)[0];
        },
        onHttpException: () => false,
        onNetworkError: () => false,
        onFinish: () => {
            busy.value = false;
            review.value?.close();
            if (!answered) failed.value = 'Not sure if this was saved. Your ticks are kept. Save again: nobody is charged twice.';
        },
    });
}

const undoDialog = ref(null);
const undoing = ref(null);
function askUndo(batch) {
    undoing.value = batch;
    undoDialog.value.open();
}
const undo = () => router.post(`/student-attendance/bulk/${undoing.value.id}/undo`, { date: props.date, meal: props.meal }, {
    preserveState: true,
    preserveScroll: true,
    onStart: () => { busy.value = true; },
    onSuccess: ({ props: { flash } }) => {
        if (flash.success && saved.value === undoing.value.id) {
            saved.value = null;
            skipped.value = [];
        }
    },
    onFinish: () => {
        busy.value = false;
        undoDialog.value?.close();
    },
});
const savedBatch = computed(() => props.batches.find(({ id, blocked }) => id === saved.value && !blocked));
</script>

<template>
    <AdminLayout title="Mark attendance">
        <AttendanceTabs :meal="meal" />
        <section v-if="savedBatch || skipped.length" class="panel result" aria-label="Result">
            <template v-if="skipped.length">
                <h2>Not marked: {{ skipped.length }}</h2>
                <ul class="rows"><li v-for="row in skipped" :key="row.id"><b>{{ row.name }}</b><span>{{ row.reason }}</span></li></ul>
            </template>
            <AppButton v-if="savedBatch" variant="plain" :icon="Undo2" :disabled="busy" @click="askUndo(savedBatch)">Undo this save</AppButton>
        </section>

        <div class="filters">
            <FormField id="bulk-date" :key="dateKey" :model-value="date" label="1. Choose the date" type="date" :max="page.props.today" @update:model-value="(value) => value && go({ date: value })" />
        </div>
        <MealChoice :model-value="meal" :meals="meals" legend="2. Choose the meal" @update:model-value="(id) => go({ meal: id })" />
        <MealMenu :meal="chosenMeal" :menu="menu" />

        <p v-if="!students.length" class="panel">No active students yet. Add one under Students.</p>
        <p v-else-if="meal === null" class="panel">Choose a meal to see the list.</p>
        <template v-else>
            <h2 class="step">3. Tick who ate {{ chosenMeal.name }}</h2>
            <div class="filters">
                <FormField id="bulk-search" v-model="search" label="Find a student" placeholder="Name, canteen ID or college" type="search" maxlength="100" autocomplete="off" />
                <FormField id="bulk-college" v-model="college" as="select" label="College">
                    <option value="">All colleges</option>
                    <option v-for="name in colleges" :key="name">{{ name }}</option>
                </FormField>
            </div>
            <div class="acts start tools-row">
                <AppButton variant="plain" :icon="CheckCheck" @click="tickShown(true)">Tick all shown</AppButton>
                <AppButton variant="plain" :icon="Square" @click="tickShown(false)">Untick all shown</AppButton>
                <AppButton variant="plain" :icon="History" :disabled="!last.length" @click="likeLastTime">Same as last time</AppButton>
            </div>
            <p v-if="!shown.length" class="panel">Nothing found. Check the spelling or clear the search.</p>
            <ul class="people">
                <li v-for="student in shown" :key="student.id">
                    <label class="person" :class="{ locked: locked(student), on: ticked.has(student.id) && !locked(student) }">
                        <input
                            type="checkbox" :checked="ticked.has(student.id) && !locked(student)" :disabled="locked(student)"
                            @change="set([student.id], $event.target.checked)"
                        >
                        <Avatar :name="student.name" :photo="student.profile_pic" />
                        <span class="about"><b>{{ displayName(student) }}</b><span class="small">{{ [student.canteen_id, student.collage_name].filter(Boolean).join(', ') }}</span></span>
                        <StatusTag v-if="student.marked" kind="ok">Already marked</StatusTag>
                        <StatusTag v-else-if="student.paid_block" kind="due">Date already paid</StatusTag>
                        <StatusTag v-else-if="student.away" kind="due">Away until {{ shortDate(student.away) }}</StatusTag>
                    </label>
                </li>
            </ul>
            <div class="savebar">
                <p v-if="failed" class="err" role="alert">{{ failed }}</p>
                <p role="status"><b>{{ chosen.length }} ticked · {{ money(total) }}</b><template v-if="hidden"> · {{ hidden }} of them {{ hidden === 1 ? 'is' : 'are' }} hidden by the search</template></p>
                <AppButton :icon="Check" :disabled="!chosen.length || busy" @click="review.open()">Review and save</AppButton>
            </div>
        </template>

        <section v-if="batches.length" class="panel recent">
            <h2>Last saves</h2>
            <ul class="rows">
                <li v-for="batch in batches" :key="batch.id">
                    <span><b>{{ batch.meal_name }}, {{ shortDate(batch.date) }}</b>: {{ batch.count }} {{ batch.count === 1 ? 'student' : 'students' }}<br><span class="small">Saved {{ batch.when }} by {{ batch.user_name }}</span></span>
                    <span v-if="batch.blocked" class="small">{{ batch.blocked }}</span>
                    <AppButton v-else variant="plain" :icon="Undo2" :disabled="busy" @click="askUndo(batch)">Undo<span class="sr-only"> {{ batch.meal_name }} of {{ shortDate(batch.date) }}</span></AppButton>
                </li>
            </ul>
        </section>

        <ConfirmDialog
            ref="review" :title="`Save ${chosenMeal?.name ?? ''} for ${chosen.length} ${chosen.length === 1 ? 'student' : 'students'}?`" :text="reviewText"
            confirm-label="Save" cancel-label="Go back" variant="primary" :icon="Check" :busy="busy" @confirm="save"
        />
        <ConfirmDialog
            ref="undoDialog" :title="undoing ? `Undo ${undoing.meal_name} of ${shortDate(undoing.date)}?` : ''"
            text="The entries of this save are deleted and taken off the bills."
            confirm-label="Undo save" cancel-label="Keep it" :icon="Undo2" :busy="busy" @confirm="undo"
        />
        <LeaveGuard :active="chosen.length > 0" :text="`${chosen.length} ticked, not saved yet.`" @stay="dateKey++" />
    </AdminLayout>
</template>

<style scoped>
.result, .step, .tools-row { margin-bottom: 1.1rem; }
.recent { margin-top: 1.6rem; }
.people { display: grid; gap: .5rem; }
.person { display: flex; align-items: center; gap: .9rem; flex-wrap: wrap; min-height: 4.2rem; padding: .5rem 1rem; background: #fff; border: 2px solid var(--color-line); border-radius: 10px; cursor: pointer; }
.person.on { border-color: var(--color-primary); background: var(--color-paper); }
.person.locked { cursor: default; background: var(--color-canvas); }
/* Keeps a focused row clear of the bar at the bottom and the message at the top. */
.person input { width: 1.7rem; height: 1.7rem; accent-color: var(--color-primary); flex: none; scroll-margin: 8rem 0 13rem; }
.person :deep(.tag) { white-space: normal; }
.about { display: grid; flex: 1 1 8rem; min-width: 0; overflow-wrap: anywhere; }
.savebar { position: sticky; bottom: 0; z-index: 1; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .6rem 1rem; margin-top: 1rem; padding: .8rem 1rem; background: #fff; border: 2px solid var(--color-navy-900); border-radius: 10px 10px 0 0; }
.savebar .err { flex-basis: 100%; }
.recent .rows li > span { min-width: 0; overflow-wrap: anywhere; }
</style>
