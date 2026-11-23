<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="page">
        <header class="wrap top">
            <Link class="brand" href="/">Canteen Attendance</Link>
            <nav aria-label="Main">
                <template v-if="!$page.props.auth.user">
                    <Link class="link" href="/login">Sign in</Link>
                    <Link class="btn" href="/register">Register</Link>
                </template>
                <Link v-else-if="$page.props.auth.user.role === 'admin'" class="link" href="/dashboard">Dashboard</Link>
                <Link v-else class="link" href="/account">My account</Link>
            </nav>
        </header>
        <slot />
    </div>
</template>

<!-- Not scoped: shared by every page inside this layout. -->
<style>
.page {
    min-height: 100vh;
    background: var(--color-navy-900);
    color: #fff;
    font: 400 1.0625rem/1.55 var(--font-sans);
}
.page :focus-visible { outline: 3px solid var(--color-turmeric); outline-offset: 3px; }
.page .wrap { max-width: 1180px; margin-inline: auto; padding-inline: 24px; }
.page .top { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding-block: 22px; }
.page .brand { font-weight: 800; font-stretch: 75%; font-size: 1.6rem; line-height: 1; }
.page .top nav { display: flex; gap: 20px; align-items: center; flex-shrink: 0; }
.page .link { text-decoration: underline; }
.page .btn {
    display: inline-block;
    border-radius: 6px;
    padding: 12px 22px;
    font-weight: 650;
    text-align: center;
    cursor: pointer;
    background: var(--color-turmeric);
    color: var(--color-navy-950);
}
.page .btn.blue { background: var(--color-primary); color: #fff; width: 100%; padding: 14px; }
.page .btn.blue:focus-visible { outline-color: var(--color-primary); }
.page .btn:hover { filter: brightness(1.07); }
.page .btn:disabled { opacity: .65; cursor: default; }
@media (max-width: 520px) {
    .page .top nav { gap: 12px; }
    .page .btn { padding: 10px 14px; }
}
@media (prefers-reduced-motion: reduce) {
    .page * { transition: none !important; }
}
</style>
