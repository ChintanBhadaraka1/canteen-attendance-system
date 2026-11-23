<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthTicket from '../../Components/AuthTicket.vue';
import FormField from '../../Components/FormField.vue';
import PublicLayout from '../../Layouts/PublicLayout.vue';

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.post('/login', { onError: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <PublicLayout>
        <AuthTicket>
            <template #stub>
                <h1>Back at the counter</h1>
                <p>Sign in to pick up where you left off.</p>
                <div class="holes" aria-hidden="true"><i></i><i></i><i></i></div>
            </template>
            <form @submit.prevent="submit">
                <FormField id="email" v-model="form.email" label="Email" type="email" autocomplete="username" required :error="form.errors.email" />
                <FormField id="password" v-model="form.password" label="Password" type="password" autocomplete="current-password" required :error="form.errors.password" />
                <label class="check"><input v-model="form.remember" type="checkbox"> Keep me signed in</label>
                <button class="btn blue" type="submit" :disabled="form.processing">Sign in</button>
            </form>
            <p class="alt">New here? <Link href="/register">Register</Link></p>
        </AuthTicket>
    </PublicLayout>
</template>

<style scoped>
.holes { display: flex; gap: 16px; margin-top: auto; }
.holes i { width: 62px; height: 62px; border-radius: 50%; background: var(--color-navy-900); box-shadow: inset 0 5px 7px #0009; }
.holes i:last-child { background: none; box-shadow: none; border: 2px dashed #b9ccff; }
.check { display: flex; gap: 9px; align-items: center; }
.check input { width: 19px; height: 19px; accent-color: var(--color-primary); }
.check input:focus-visible { outline-color: var(--color-primary); }
</style>
