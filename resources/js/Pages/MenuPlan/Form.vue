<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { CircleStop, Save } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { longDate, shortDate, weekday } from '../../format';

const props = defineProps({
    meal: { type: Object, required: true },
    date: { type: String, required: true },
    plan: { type: Object, default: null },
    items: { type: Array, required: true },
});

const names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const list = (words) => (words.length < 2 ? words.join('') : `${words.slice(0, -1).join(', ')} and ${words.at(-1)}`);

const form = useForm({
    closed: props.plan?.closed ?? false,
    items: (props.plan?.items ?? []).filter((item) => item.available).map((item) => item.id),
    note: props.plan?.note ?? '',
    repeat: 'once',
    weekdays: [names.indexOf(weekday(props.date)) + 1],
    until: '',
});
const gone = computed(() => (props.plan?.items ?? []).filter((item) => !item.available).map((item) => item.name));

const search = ref('');
const groups = computed(() => {
    const word = search.value.trim().toLowerCase();
    const shown = props.items.filter((item) => !word || `${item.name} ${item.food_type ?? ''}`.toLowerCase().includes(word));
    const map = new Map();
    [...shown].sort((a, b) => (a.place ?? Infinity) - (b.place ?? Infinity)).forEach((item) => {
        const key = item.category ?? 'No category';
        if (!map.has(key)) map.set(key, []);
        map.get(key).push(item);
    });
    return [...map];
});
const chosenNames = computed(() => form.items.map((id) => props.items.find((item) => item.id === id)?.name).filter(Boolean));

function toggleDay(day) {
    form.weekdays = form.weekdays.includes(day) ? form.weekdays.filter((other) => other !== day) : [...form.weekdays, day].sort();
}
const days = computed(() => (form.repeat === 'daily' ? [1, 2, 3, 4, 5, 6, 7] : form.weekdays));
const sentence = computed(() => {
    if (form.repeat === 'once') return `Only on ${longDate(props.date)}.`;
    if (!days.value.length) return 'Choose at least one day.';
    const which = days.value.length === 7 ? 'day' : list(days.value.map((day) => names[day - 1]));
    return `Every ${which} from ${shortDate(props.date)}, until ${form.until ? shortDate(form.until) : 'you change it'}.`;
});

const save = () => form.transform(({ repeat, ...data }) => ({
    ...data,
    meal_id: props.meal.id,
    from: props.date,
    repeat: repeat === 'once' ? 'once' : 'weekly',
    weekdays: days.value,
    items: data.closed ? [] : data.items,
})).post('/menu-plan', { onError: focusFirstError });

const dialog = ref(null);
const busy = ref(false);
const stopText = computed(() => {
    const day = props.plan?.repeat === 'once' ? shortDate(props.date) : `Every ${weekday(props.date)}`;
    const after = props.plan?.instead ? `${day} will show the earlier menu again: ${props.plan.instead}.` : 'Nothing will be planned.';
    return props.plan?.repeat === 'once' ? after : `Past days stay as they were. ${after}`;
});
const stop = () => router.post(`/menu-plan/${props.plan.plan_id}/stop`, { date: props.date }, {
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        dialog.value?.close();
    },
});
const repeats = [{ key: 'once', label: 'Only this date' }, { key: 'weekly', label: 'Every week on…' }, { key: 'daily', label: 'Every day' }];
</script>

<template>
    <AdminLayout :title="`${meal.name}, ${longDate(date)}`" :back="{ href: `/menu-plan?week=${date}`, label: 'Back to week menu' }">
        <section v-if="plan" class="panel now">
            <p>Now: <b>{{ plan.closed ? 'No meal' : plan.items.map((item) => item.name).join(', ') }}</b> ({{ plan.repeat === 'once' ? 'only this date' : `every ${weekday(date)}${plan.until ? ` until ${shortDate(plan.until)}` : ''}` }})</p>
            <AppButton variant="danger" :icon="CircleStop" @click="dialog.open()">{{ plan.repeat === 'once' ? 'Remove this menu' : `Stop every ${weekday(date)}` }}</AppButton>
        </section>
        <form class="stack" novalidate @submit.prevent="save">
            <fieldset class="panel seg">
                <legend class="sr-only">Is there a meal?</legend>
                <button type="button" :aria-pressed="!form.closed" @click="form.closed = false">There is a meal</button>
                <button type="button" :aria-pressed="form.closed" @click="form.closed = true">No meal</button>
            </fieldset>
            <section v-if="!form.closed" class="panel stack">
                <h2>Choose the items</h2>
                <p class="chosen" aria-live="polite"><b>{{ form.items.length }} chosen</b><template v-if="chosenNames.length">: {{ chosenNames.join(', ') }}</template></p>
                <p v-if="gone.length" class="warn">Not available now: {{ gone.join(', ') }}</p>
                <FormField id="item-search" v-model="search" label="Find an item" type="search" maxlength="100" autocomplete="off" />
                <p v-if="!items.length">No menu items yet. Add one under Menu items.</p>
                <p v-else-if="!groups.length">Nothing found. Check the spelling or clear the search.</p>
                <fieldset v-for="[name, group] in groups" :key="name">
                    <legend>{{ name }}</legend>
                    <div class="ticks">
                        <label v-for="item in group" :key="item.id" :class="{ on: form.items.includes(item.id) }">
                            <input v-model="form.items" type="checkbox" :value="item.id" :aria-invalid="form.errors.items ? 'true' : undefined">
                            <span>{{ item.name }}<span v-if="item.food_type" class="small">{{ ` ${item.food_type}` }}</span></span>
                        </label>
                    </div>
                </fieldset>
                <p v-if="form.errors.items" class="err" role="alert">{{ form.errors.items }}</p>
            </section>
            <section class="panel stack">
                <FormField id="note" v-model="form.note" label="Note (optional)" maxlength="100" autocomplete="off" :error="form.errors.note" />
                <fieldset class="seg three">
                    <legend>Repeat</legend>
                    <button v-for="option in repeats" :key="option.key" type="button" :aria-pressed="form.repeat === option.key" @click="form.repeat = option.key">{{ option.label }}</button>
                </fieldset>
                <fieldset v-if="form.repeat === 'weekly'" class="seg weekdays">
                    <legend>Days</legend>
                    <button v-for="(name, index) in names" :key="name" type="button" :aria-pressed="form.weekdays.includes(index + 1)" @click="toggleDay(index + 1)">{{ name }}</button>
                </fieldset>
                <p v-if="form.errors.weekdays" class="err" role="alert">{{ form.errors.weekdays }}</p>
                <FormField v-if="form.repeat !== 'once'" id="until" v-model="form.until" label="Until (optional)" type="date" :min="date" :error="form.errors.until" />
                <p v-if="form.errors.from" class="err" role="alert">{{ form.errors.from }}</p>
                <p class="after" aria-live="polite">{{ sentence }}<template v-if="form.repeat !== 'once' && plan?.repeat === 'once'"> {{ shortDate(date) }} keeps its own menu.</template></p>
                <div class="acts start">
                    <AppButton type="submit" :icon="Save" :disabled="form.processing">Save menu</AppButton>
                    <AppButton variant="plain" :href="`/menu-plan?week=${date}`">Cancel</AppButton>
                </div>
            </section>
        </form>
        <ConfirmDialog
            v-if="plan" ref="dialog" :title="plan.repeat === 'once' ? 'Remove this menu?' : `Stop every ${weekday(date)}?`" :text="stopText"
            :confirm-label="plan.repeat === 'once' ? 'Remove menu' : 'Stop from today'" cancel-label="Keep it" :icon="CircleStop" :busy="busy" @confirm="stop"
        />
    </AdminLayout>
</template>

<style scoped>
.now { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.2rem; overflow-wrap: anywhere; }
fieldset, .ticks label { min-width: 0; }
fieldset legend { font-weight: 650; margin-bottom: .4rem; }
.ticks { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 15rem), 1fr)); gap: .5rem; }
.ticks label { display: flex; align-items: center; gap: .8rem; min-height: 3.4rem; padding: .4rem .9rem; border: 2px solid var(--color-line); border-radius: 10px; cursor: pointer; overflow-wrap: anywhere; }
.ticks label.on { border-color: var(--color-primary); background: var(--color-paper); }
.ticks input { width: 1.6rem; height: 1.6rem; accent-color: var(--color-primary); flex: none; }
.ticks span { min-width: 0; }
.three { grid-template-columns: repeat(auto-fit, minmax(min(100%, 11rem), 1fr)); }
.weekdays { grid-template-columns: repeat(auto-fit, minmax(min(100%, 9rem), 1fr)); }
.chosen { overflow-wrap: anywhere; min-width: 0; }
.warn { color: var(--color-due); font-weight: 600; overflow-wrap: anywhere; }
</style>
