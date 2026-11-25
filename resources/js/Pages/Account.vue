<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthTicket from '../Components/AuthTicket.vue';
import MealCard from '../Components/MealCard.vue';
import StudentMenu from '../Components/StudentMenu.vue';
import PublicLayout from '../Layouts/PublicLayout.vue';
import { longDate } from '../format';

const props = defineProps({
    student: { type: Object, required: true },
    menu: { type: Array, required: true },
});

const form = useForm({});
const planned = computed(() => props.menu.filter((day) => day.meals.length));
</script>

<template>
    <Head title="My account" />
    <PublicLayout>
        <AuthTicket>
            <template #stub>
                <h1>Your meal card</h1>
                <MealCard :name="student.name" :college="student.collage_name ?? ''" :canteen-id="student.canteen_id ?? ''">
                    <template v-if="!student.canteen_id" #default>Collect your canteen ID at the counter</template>
                </MealCard>
            </template>
            <form @submit.prevent="form.post('/logout')">
                <button class="btn blue" type="submit" :disabled="form.processing">Sign out</button>
            </form>
        </AuthTicket>
        <section v-if="planned.length" class="wrap week">
            <h2>Menu of the next 7 days</h2>
            <div v-for="day in planned" :key="day.date" class="day">
                <h3>{{ longDate(day.date) }}</h3>
                <StudentMenu :meals="day.meals" />
            </div>
        </section>
    </PublicLayout>
</template>

<style scoped>
.week { padding-bottom: 56px; }
.week h2 { font-weight: 750; font-stretch: 80%; font-size: 2.2rem; line-height: 1.1; }
.day { margin-top: 28px; }
.day > h3 { font-weight: 700; font-size: 1.4rem; margin-bottom: 12px; color: var(--color-turmeric); }
</style>
