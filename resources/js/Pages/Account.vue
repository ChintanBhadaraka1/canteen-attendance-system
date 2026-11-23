<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthTicket from '../Components/AuthTicket.vue';
import MealCard from '../Components/MealCard.vue';
import PublicLayout from '../Layouts/PublicLayout.vue';

defineProps({ student: { type: Object, required: true } });

const form = useForm({});
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
    </PublicLayout>
</template>
