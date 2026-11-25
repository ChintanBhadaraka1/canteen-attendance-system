<script>
// Saved tokens of this visit: the browser's Back button can show an old page again.
const used = new Set();
</script>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { Banknote, Check, CircleAlert, CreditCard, HandCoins, RefreshCw, Smartphone, UserX } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import AttendanceTabs from '../../Components/AttendanceTabs.vue';
import Avatar from '../../Components/Avatar.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import CountButtons from '../../Components/CountButtons.vue';
import FormField from '../../Components/FormField.vue';
import GuestRows from '../../Components/GuestRows.vue';
import MealChoice from '../../Components/MealChoice.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { useDatePrices } from '../../datePrices';
import { focusFirstError } from '../../forms';
import { displayName, money } from '../../format';

const props = defineProps({
    type: { type: String, default: null },
    date: { type: String, required: true },
    meal: { type: Number, default: null },
    meals: { type: Array, required: true },
    extras: { type: Array, required: true },
    students: { type: Array, required: true },
    student: { type: Object, default: null },
    recent: { type: Array, required: true },
    token: { type: String, required: true },
});

const today = usePage().props.today;
const type = ref(props.type);
const form = useForm({
    meal_id: props.meal,
    date: props.date,
    plates: 1,
    extras: Object.fromEntries(props.extras.map((extra) => [extra.id, 0])),
    guest_name: '',
    method: '',
});
const { loading, failed, load } = useDatePrices(form, props, today, ['date', 'meals', 'student', 'recent']);

onMounted(() => { if (used.has(props.token)) router.reload({ only: ['token', 'recent', 'student'] }); });

const search = ref('');
const finding = ref(false);
const found = computed(() => {
    const word = search.value.trim().toLowerCase();
    if (!word) return [];
    const exact = props.students.filter((student) => student.canteen_id?.toLowerCase() === word);
    return exact.length ? exact : props.students.filter((student) => `${displayName(student)} ${student.canteen_id ?? ''}`.toLowerCase().includes(word));
});
const take = (id) => router.reload({
    data: { student: id, type: type.value },
    only: ['student'],
    replace: true,
    onStart: () => { finding.value = true; },
    onSuccess: () => { search.value = ''; form.clearErrors(); },
    onFinish: () => {
        finding.value = false;
        if (!props.student) nextTick(() => document.getElementById('who')?.focus());
    },
});
function enter() {
    if (found.value.length === 1) take(found.value[0].id);
}

const name = computed(() => (props.student ? displayName(props.student) : ''));
const meal = computed(() => props.meals.find(({ id }) => id === form.meal_id));
const total = computed(() => form.plates * Number(meal.value?.price ?? 0)
    + props.extras.reduce((sum, extra) => sum + (form.extras[extra.id] ?? 0) * Number(extra.price), 0));
const plates = computed(() => `${form.plates} ${type.value === 'card' ? 'guest ' : ''}${form.plates === 1 ? 'plate' : 'plates'}`);
const summary = computed(() => (type.value === 'card'
    ? `${plates.value} of ${meal.value?.name}, ${money(total.value)}, on the card of ${name.value}`
    : `${plates.value} of ${meal.value?.name}`));
const ready = computed(() => meal.value && (type.value === 'walk_in' || props.student));

const dialog = ref(null);
const unanswered = ref(false);
function ask() {
    form.clearErrors();
    if (type.value === 'card' && !props.student) form.setError('student_id', 'Choose the student.');
    if (!meal.value) form.setError('meal_id', 'Choose a meal.');
    if (!form.date || form.date > today) form.setError('date', 'Choose today or an earlier date.');
    if (type.value === 'walk_in' && !form.method) form.setError('method', 'Choose cash or UPI.');
    if (form.hasErrors) return focusFirstError();
    if (loading.value || finding.value || form.processing) return;
    if (form.plates > 5) return dialog.value.open();
    save();
}
function save() {
    const token = props.token;
    unanswered.value = false;
    form.transform((data) => ({ ...data, token, expected_total: total.value, student_id: props.student?.id }))
        .post(`/student-attendance/guest/${type.value === 'card' ? 'card' : 'walk-in'}`, {
            preserveScroll: 'errors',
            onSuccess: () => {
                used.add(token);
                Object.assign(form, { plates: 1, guest_name: '', method: '', extras: Object.fromEntries(props.extras.map((extra) => [extra.id, 0])) });
            },
            onError: focusFirstError,
            onHttpException: () => { unanswered.value = true; return false; },
            onNetworkError: () => { unanswered.value = true; return false; },
            onFinish: () => dialog.value?.close(),
        });
}
</script>

<template>
    <AdminLayout title="Mark attendance">
        <AttendanceTabs :meal="meal?.id ?? null" />
        <fieldset class="seg kinds">
            <legend>How is the guest paid for?</legend>
            <button type="button" :aria-pressed="type === 'card'" @click="type = 'card'"><CreditCard aria-hidden="true" />On a student's card</button>
            <button type="button" :aria-pressed="type === 'walk_in'" @click="type = 'walk_in'"><HandCoins aria-hidden="true" />Pays now</button>
        </fieldset>
        <form v-if="type" class="stack" novalidate @submit.prevent="ask">
            <section v-if="type === 'card'" class="panel stack">
                <h2>Student</h2>
                <template v-if="student">
                    <div class="who holder">
                        <Avatar :name="student.name" :photo="student.profile_pic" />
                        <span><b>{{ name }}</b><br>Canteen ID {{ student.canteen_id ?? 'not given' }}<br>{{ student.due > 0 ? `${money(student.due)} due` : student.due < 0 ? `${money(-student.due)} advance` : 'Nothing due' }}</span>
                    </div>
                    <p v-if="meal">{{ student.own.includes(meal.id) ? `${name} has been marked for ${meal.name}.` : `${name} has not been marked for ${meal.name}.` }}</p>
                    <p v-if="student.away" class="warn"><CircleAlert aria-hidden="true" />{{ name }} is noted as away on this date. You can still save.</p>
                    <div><AppButton variant="plain" :icon="UserX" :disabled="finding" @click="take('')">Choose another student</AppButton></div>
                </template>
                <template v-else>
                    <FormField id="who" v-model="search" label="Canteen ID or name" autocomplete="off" spellcheck="false" :error="form.errors.student_id" @keydown.enter.prevent="enter" />
                    <p v-if="search.trim() && !found.length" role="status">No student found.</p>
                    <ul v-if="found.length" class="rows">
                        <li v-for="one in found.slice(0, 8)" :key="one.id">
                            <span class="who"><Avatar :name="one.name" :photo="one.profile_pic" /><span><b>{{ displayName(one) }}</b><br><span class="small">{{ one.canteen_id ?? 'No canteen ID' }}</span></span></span>
                            <AppButton variant="plain" :icon="Check" :disabled="finding" @click="take(one.id)">Choose<span class="sr-only"> {{ displayName(one) }}</span></AppButton>
                        </li>
                    </ul>
                    <p v-if="found.length > 8">{{ found.length - 8 }} more. Type more letters.</p>
                </template>
            </section>
            <section class="panel stack">
                <FormField id="date" v-model="form.date" label="Date" type="date" :max="today" :error="form.errors.date" />
                <MealChoice v-model="form.meal_id" :meals="meals" legend="Choose the meal" :error="form.errors.meal_id" />
                <div>
                    <p class="label">Plates</p>
                    <CountButtons v-model="form.plates" name="plates" :min="1" :max="20" />
                    <p v-if="form.errors.plates" class="err" role="alert">{{ form.errors.plates }}</p>
                </div>
                <div v-if="extras.length">
                    <p class="label">Extra items, for all plates together</p>
                    <ul class="rows">
                        <li v-for="extra in extras" :key="extra.id">
                            <span>{{ extra.name }} <span class="small">{{ money(extra.price) }} each</span></span>
                            <CountButtons v-model="form.extras[extra.id]" :name="extra.name" />
                        </li>
                    </ul>
                </div>
                <FormField id="guest_name" v-model="form.guest_name" label="Guest name (optional)" maxlength="60" autocomplete="off" :error="form.errors.guest_name" />
                <div v-if="type === 'walk_in'">
                    <fieldset class="seg">
                        <legend>How is it paid?</legend>
                        <button type="button" :aria-pressed="form.method === 'cash'" :aria-invalid="form.errors.method ? 'true' : undefined" @click="form.method = 'cash'"><Banknote aria-hidden="true" />Cash</button>
                        <button type="button" :aria-pressed="form.method === 'upi'" @click="form.method = 'upi'"><Smartphone aria-hidden="true" />UPI</button>
                    </fieldset>
                    <p v-if="form.errors.method" class="err" role="alert">{{ form.errors.method }}</p>
                </div>
                <div class="after" aria-live="polite">
                    <p v-if="loading">Loading the price of this date…</p>
                    <p v-else-if="!ready">{{ type === 'card' ? 'Choose the student and the meal.' : 'Choose the meal.' }}</p>
                    <template v-else>
                        <p>{{ summary }}</p>
                        <p v-if="type === 'walk_in'" class="collect">Collect {{ money(total) }}</p>
                    </template>
                </div>
                <div v-if="failed && loading" class="acts start">
                    <p class="err" role="alert">The price could not be loaded.</p>
                    <AppButton variant="plain" :icon="RefreshCw" @click="load">Try again</AppButton>
                </div>
                <p v-if="form.errors.guest" class="err" role="alert">{{ form.errors.guest }}</p>
                <p v-if="form.errors.token || form.errors.expected_total || form.errors.extras" class="err" role="alert">This could not be saved. Reload the page and try again.</p>
                <p v-if="unanswered" class="err" role="alert">No answer from the server. Save again: it is never added twice.</p>
                <div class="acts start">
                    <AppButton type="submit" :icon="Check" :disabled="form.processing || loading || finding">{{ type === 'card' ? 'Add to the card' : 'Money received, save' }}</AppButton>
                </div>
            </section>
        </form>
        <section class="panel recent">
            <h2>{{ date === today ? 'Last guests today' : 'Last guests of this date' }}</h2>
            <p v-if="!recent.length">Nobody yet.</p>
            <GuestRows :rows="recent" :back="{ student: String(student?.id ?? '') }" />
        </section>
        <ConfirmDialog
            ref="dialog" :title="type === 'card' ? `${form.plates} plates for ${name}?` : `${form.plates} plates?`" :text="ready ? `${summary}. Total ${money(total)}.` : ''"
            confirm-label="Save" cancel-label="Go back" variant="primary" :icon="Check" :busy="form.processing" @confirm="save"
        />
    </AdminLayout>
</template>

<style scoped>
.kinds, .recent { margin-bottom: 1.2rem; }
.kinds { grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr)); }
.recent { margin-top: 1.2rem; }
.holder { min-width: 0; overflow-wrap: anywhere; }
.holder :deep(.ava) { width: 4.5rem; height: 4.5rem; font-size: 1.8rem; }
.after { overflow-wrap: anywhere; }
.collect { font-size: 2rem; font-weight: 800; }
.warn { display: flex; gap: .5rem; align-items: center; color: var(--color-due); font-weight: 600; }
.warn svg { width: 1.45rem; height: 1.45rem; flex: none; }
.stack :deep(.meals) { margin-bottom: 0; }
</style>
