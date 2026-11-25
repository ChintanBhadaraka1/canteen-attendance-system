<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Check, Download, History, Plus, UserPlus } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import AttendanceTabs from '../../Components/AttendanceTabs.vue';
import Avatar from '../../Components/Avatar.vue';
import DataList from '../../Components/DataList.vue';
import DateRange from '../../Components/DateRange.vue';
import MealChoice from '../../Components/MealChoice.vue';
import MealMenu from '../../Components/MealMenu.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { displayName } from '../../format';

const props = defineProps({
    meals: { type: Array, required: true },
    meal: { type: Number, default: null },
    menu: { type: Object, required: true },
    students: { type: Object, required: true },
    filters: { type: Object, required: true },
    range: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Student', sortable: true },
    { key: 'canteen_id', label: 'Canteen ID', sortable: true },
    { key: 'collage_name', label: 'College', sortable: true },
];
const list = ref(null);
const mealName = computed(() => props.meals.find((meal) => meal.id === props.meal)?.name);

const marking = ref(null);
const failed = ref('');

// aria-disabled, not disabled, so the keyboard stays on the button.
function mark(row) {
    if (marking.value || row.marked || props.meal === null) return;
    const { search, sort, dir } = props.filters;
    router.post('/student-attendance/store', {
        user_id: row.id, meal_id: props.meal, include_extra: 'no', return_to: 'list',
        search, sort, dir, page: props.students.current_page,
    }, {
        preserveState: true,
        preserveScroll: true,
        onStart: () => {
            marking.value = row.id;
            failed.value = '';
        },
        onError: () => { failed.value = 'This could not be marked. Reload the page and try again.'; },
        onFinish: () => { marking.value = null; },
    });
}
const markLabel = (row) => (row.marked ? 'Marked' : marking.value === row.id ? 'Marking…' : `Mark ${mealName.value}`);

const downloadOpen = ref(false);
const from = ref(props.range.from);
const to = ref(props.range.to);
const downloadUrl = computed(() => `/student-attendance/download?${new URLSearchParams({ from: from.value, to: to.value })}`);
</script>

<template>
    <AdminLayout title="Mark attendance">
        <template #actions>
            <AppButton variant="plain" :icon="Download" :aria-expanded="downloadOpen" @click="downloadOpen = !downloadOpen">Download attendance</AppButton>
        </template>
        <AttendanceTabs :meal="meal" />
        <section v-if="downloadOpen" class="panel filters" aria-label="Download attendance">
            <DateRange id="download" v-model:from="from" v-model:to="to" />
            <AppButton v-if="from && to" :icon="Download" :href="downloadUrl" external>Download file</AppButton>
            <p v-else>Choose both dates.</p>
        </section>
        <p v-if="failed" class="err" role="alert">{{ failed }}</p>
        <MealChoice :model-value="meal" :meals="meals" legend="1. Choose the meal" @update:model-value="(id) => list.visit({ meal: id, page: students.current_page })" />
        <MealMenu :meal="meals.find(({ id }) => id === meal)" :menu="menu" />
        <DataList
            ref="list" :columns="columns" :rows="students" :filters="filters" noun="student"
            search-label="2. Find the student" search-hint="Name, canteen ID or college"
        >
            <template #cell-name="{ row }">
                <div class="who"><Avatar :name="row.name" :photo="row.profile_pic" /><b>{{ displayName(row) }}</b></div>
            </template>
            <template #actions="{ row }">
                <AppButton variant="plain" :icon="History" :href="`/student-attendance/show/${row.id}`">History<span class="sr-only"> of {{ displayName(row) }}</span></AppButton>
                <AppButton variant="plain" :icon="Plus" :href="`/student-attendance/create/${row.id}${meal === null ? '' : `?meal=${meal}`}`">Date or extras<span class="sr-only"> for {{ displayName(row) }}</span></AppButton>
                <AppButton variant="plain" :icon="UserPlus" :href="`/student-attendance/guest?type=card&student=${row.id}${meal === null ? '' : `&meal=${meal}`}`">Add guest<span class="sr-only"> of {{ displayName(row) }}</span></AppButton>
                <AppButton
                    v-if="meal !== null" :variant="row.marked ? 'mark done' : 'mark'" :icon="Check"
                    :aria-disabled="row.marked || marking !== null ? 'true' : undefined" @click="mark(row)"
                >{{ markLabel(row) }}<span class="sr-only"> {{ displayName(row) }}</span></AppButton>
                <span v-else class="small choose">Choose a meal first</span>
            </template>
        </DataList>
    </AdminLayout>
</template>

<style scoped>
.choose { display: grid; place-items: center; min-height: 3.1rem; min-width: 10.5rem; }
</style>
