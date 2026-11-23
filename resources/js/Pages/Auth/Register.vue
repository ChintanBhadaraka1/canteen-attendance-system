<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AuthTicket from '../../Components/AuthTicket.vue';
import FormField from '../../Components/FormField.vue';
import MealCard from '../../Components/MealCard.vue';
import PublicLayout from '../../Layouts/PublicLayout.vue';

const form = useForm({
    name: '',
    last_name: '',
    collage_name: '',
    email: '',
    phone_number: '',
    password: '',
    password_confirmation: '',
});
const cardName = computed(() => [form.name, form.last_name].map((part) => part.trim()).filter(Boolean).join(' '));

function submit() {
    form.post('/register', {
        onError: () => form.reset('password', 'password_confirmation'),
        onHttpException: (response) => {
            if (response.status !== 429) return;
            form.setError('email', 'Too many attempts. Please try again in a minute.');
            return false;
        },
    });
}
</script>

<template>
    <Head title="Register" />
    <PublicLayout>
        <AuthTicket>
            <template #stub>
                <h1>Print your meal card</h1>
                <p>It fills in as you type.</p>
                <MealCard :name="cardName" :college="form.collage_name.trim()">Canteen ID is given at the counter</MealCard>
            </template>
            <form @submit.prevent="submit">
                <div class="row">
                    <FormField id="name" v-model="form.name" label="First name" autocomplete="given-name" maxlength="30" required :error="form.errors.name" />
                    <FormField id="last_name" v-model="form.last_name" label="Last name (optional)" autocomplete="family-name" maxlength="30" :error="form.errors.last_name" />
                </div>
                <FormField id="collage_name" v-model="form.collage_name" label="College" autocomplete="organization" maxlength="30" required :error="form.errors.collage_name" />
                <div class="row">
                    <FormField id="email" v-model="form.email" label="Email" type="email" autocomplete="email" required :error="form.errors.email" />
                    <FormField id="phone_number" v-model="form.phone_number" label="Phone number" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="10" required :error="form.errors.phone_number" />
                </div>
                <div class="row">
                    <FormField id="password" v-model="form.password" label="Password" type="password" autocomplete="new-password" required :error="form.errors.password" />
                    <FormField id="password_confirmation" v-model="form.password_confirmation" label="Confirm password" type="password" autocomplete="new-password" required :error="form.errors.password_confirmation" />
                </div>
                <button class="btn blue" type="submit" :disabled="form.processing">Register</button>
            </form>
            <p class="alt">Already registered? <Link href="/login">Sign in</Link></p>
        </AuthTicket>
    </PublicLayout>
</template>

<style scoped>
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
@media (max-width: 520px) {
    .row { grid-template-columns: minmax(0, 1fr); }
}
</style>
