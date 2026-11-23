<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, CircleAlert, CircleCheck, ClipboardCheck, House, IndianRupee, LogOut, ReceiptIndianRupee, Users, Utensils } from '@lucide/vue';
import AppButton from '../Components/AppButton.vue';
import { longDate } from '../format';

defineProps({
    title: { type: String, required: true },
    back: { type: Object, default: undefined },
});

const page = usePage();

const menu = [
    { href: '/dashboard', label: 'Home', icon: House },
    { href: '/student-attendance', label: 'Mark attendance', icon: ClipboardCheck },
    { href: '/bill', label: 'Bills and payments', icon: ReceiptIndianRupee },
    { href: '/user', label: 'Students', icon: Users },
    { href: '/menus', label: 'Menu', icon: Utensils },
    { href: '/meal-price', label: 'Meal prices', icon: IndianRupee },
];
const isCurrent = (href) => page.url.split('?')[0].split('/')[1] === href.slice(1);

const sizes = [
    { key: 'normal', label: 'Normal text' },
    { key: 'large', label: 'Large text' },
    { key: 'largest', label: 'Largest text' },
];
const classes = sizes.map(({ key }) => `text-${key}`);
const size = ref('normal');
try {
    const saved = localStorage.getItem('text-size');
    if (sizes.some(({ key }) => key === saved)) size.value = saved;
} catch {
    // Storage can be blocked; the normal size still works.
}
function applySize(key) {
    document.documentElement.classList.remove(...classes);
    document.documentElement.classList.add(`text-${key}`);
}
function chooseSize(key) {
    size.value = key;
    applySize(key);
    try {
        localStorage.setItem('text-size', key);
    } catch {
        // Not remembered, but applied for now.
    }
}
applySize(size.value);
onBeforeUnmount(() => document.documentElement.classList.remove(...classes));

// Kept here so a search or a page change does not clear a message before it is closed.
const message = ref(null);
watch(() => page.props.flash, (flash) => {
    if (flash.error) message.value = { text: flash.error, error: true };
    else if (flash.success) message.value = { text: flash.success, error: false };
}, { immediate: true });

const leaving = ref(false);
const signOut = () => router.post('/logout', {}, {
    onStart: () => { leaving.value = true; },
    onFinish: () => { leaving.value = false; },
});
</script>

<template>
    <Head :title="title" />
    <div class="admin">
        <div class="app">
            <aside class="bar">
                <div class="brand">Canteen Attendance</div>
                <nav aria-label="Main">
                    <Link v-for="item in menu" :key="item.href" :href="item.href" :aria-current="isCurrent(item.href) ? 'page' : undefined">
                        <component :is="item.icon" aria-hidden="true" />{{ item.label }}
                    </Link>
                </nav>
                <div class="end">
                    <button type="button" :disabled="leaving" @click="signOut"><LogOut aria-hidden="true" />Sign out</button>
                </div>
            </aside>
            <main class="main">
                <div class="tools">
                    <span>{{ longDate(page.props.today) }}</span>
                    <div class="size" role="group" aria-label="Text size">
                        <span aria-hidden="true">Text size</span>
                        <button
                            v-for="option in sizes" :key="option.key" type="button" :aria-label="option.label"
                            :aria-pressed="size === option.key" @click="chooseSize(option.key)"
                        >A</button>
                    </div>
                </div>
                <div class="head">
                    <h1>{{ title }}</h1>
                    <div v-if="back || $slots.actions" class="acts">
                        <AppButton v-if="back" variant="plain" :icon="ArrowLeft" :href="back.href">{{ back.label }}</AppButton>
                        <slot name="actions" />
                    </div>
                </div>
                <div class="note-spot" aria-live="polite">
                    <div v-if="message" class="note" :class="{ error: message.error }" :role="message.error ? 'alert' : undefined">
                        <component :is="message.error ? CircleAlert : CircleCheck" aria-hidden="true" />
                        <span>{{ message.text }}</span>
                        <button type="button" @click="message = null">Close</button>
                    </div>
                </div>
                <slot />
            </main>
        </div>
    </div>
</template>

<!-- Not scoped: shared by every page inside this layout. Container queries in rem also react to the text size. -->
<style>
.admin { container: admin / inline-size; min-height: 100vh; background: var(--color-canvas); color: var(--color-ink); font: 400 1rem/1.5 var(--font-sans); }
.admin svg { width: 1.45rem; height: 1.45rem; flex: none; }
.admin :focus-visible { outline: 3px solid var(--color-primary); outline-offset: 3px; }
.admin button { cursor: pointer; }
.admin button:disabled, .admin [aria-disabled="true"] { cursor: default; }
.admin .app { display: grid; grid-template-columns: 15.5rem minmax(0, 1fr); min-height: 100vh; }

.admin .bar { background: var(--color-navy-900); color: var(--color-navy-text); padding: 1.2rem .8rem; display: flex; flex-direction: column; gap: .5rem; position: sticky; top: 0; height: 100vh; overflow: auto; }
.admin .bar nav { display: flex; flex-direction: column; gap: 8px; }
.admin .bar :focus-visible { outline-color: var(--color-turmeric); outline-offset: -3px; }
.admin .brand { font-weight: 800; font-stretch: 75%; font-size: 1.55rem; line-height: 1.05; color: #fff; padding: .2rem .8rem 1.1rem; }
.admin .bar a, .admin .bar button { display: flex; align-items: center; gap: .8rem; min-height: 3.1rem; padding: .4rem .8rem; border-radius: 8px; font-weight: 550; text-align: left; width: 100%; }
.admin .bar a:hover, .admin .bar button:hover { background: var(--color-navy-700); color: #fff; }
.admin .bar a[aria-current] { background: var(--color-turmeric); color: var(--color-navy-950); font-weight: 700; }
.admin .bar .end { margin-top: auto; border-top: 1px solid var(--color-navy-700); padding-top: .7rem; }

.admin .main { padding: 1.5rem 2rem 4rem; max-width: 74rem; width: 100%; min-width: 0; }
.admin .tools { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.4rem; color: var(--color-muted); }
.admin .size { display: flex; align-items: center; gap: 8px; }
.admin .size button { min-width: 2.8rem; min-height: 2.8rem; border: 2px solid var(--color-field); background: #fff; border-radius: 8px; font-weight: 700; color: var(--color-ink); }
.admin .size button:nth-of-type(2) { font-size: 1.15rem; }
.admin .size button:nth-of-type(3) { font-size: 1.32rem; }
.admin .size button[aria-pressed="true"] { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
.admin .head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.3rem; }
.admin h1 { font-weight: 780; font-stretch: 78%; font-size: 2.35rem; line-height: 1.05; overflow-wrap: anywhere; min-width: 0; }
.admin h2 { font-weight: 700; font-size: 1.25rem; overflow-wrap: anywhere; }

.admin .btn { display: inline-flex; align-items: center; justify-content: center; gap: .6rem; min-height: 3.1rem; padding: .5rem 1.2rem; border-radius: 8px; border: 2px solid var(--color-primary); background: var(--color-primary); color: #fff; font-weight: 650; text-align: center; overflow-wrap: normal; }
.admin .btn:hover { background: #163fb0; }
.admin .btn.plain { background: #fff; color: var(--color-primary); }
.admin .btn.plain:hover { background: var(--color-paper); }
.admin .btn.danger { background: #fff; color: var(--color-danger); border-color: var(--color-danger); }
.admin .btn.danger:hover { background: #fdecec; }
.admin .btn.danger.solid { background: var(--color-danger); color: #fff; }
.admin .btn:disabled { opacity: .55; }
.admin .mark { min-width: 10.5rem; }
.admin .mark.done, .admin .mark.done:hover { background: var(--color-ok-bg); border-color: var(--color-ok); color: var(--color-ok); }

.admin .panel { background: #fff; border: 1px solid var(--color-line); border-radius: 10px; padding: 1.3rem; min-width: 0; }
.admin .stack { display: grid; gap: 1.2rem; align-content: start; }
.admin .two { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr); gap: 1.5rem; }
.admin .small { color: var(--color-muted); overflow-wrap: anywhere; }
.admin .acts { display: flex; gap: .5rem; flex-wrap: wrap; justify-content: flex-end; }
.admin .acts.start { justify-content: flex-start; }
.admin .who { display: flex; align-items: center; gap: .8rem; min-width: 0; }
.admin .who span, .admin .who b { overflow-wrap: anywhere; }
.admin .ava { width: 2.9rem; height: 2.9rem; border-radius: 50%; background: var(--color-navy-900); color: #fff; display: grid; place-items: center; font-weight: 700; flex: none; object-fit: cover; }
.admin .tag { display: inline-flex; gap: .35rem; align-items: center; border-radius: 6px; padding: .15rem .6rem; font-weight: 650; white-space: nowrap; }
.admin .tag svg { width: 1.1rem; height: 1.1rem; }
.admin .tag.due { background: var(--color-due-bg); color: var(--color-due); }
.admin .tag.ok { background: var(--color-ok-bg); color: var(--color-ok); }

.admin table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--color-line); }
.admin th { text-align: left; background: var(--color-paper); padding: .35rem 1rem; font-weight: 650; height: 3.1rem; }
.admin td { padding: .8rem 1rem; border-top: 1px solid var(--color-line); vertical-align: middle; overflow-wrap: anywhere; }
.admin td .acts { flex-wrap: nowrap; }
.admin .sort { display: inline-flex; align-items: center; gap: .4rem; min-height: 2.8rem; font-weight: 650; text-align: left; }
.admin .sort svg { width: 1.1rem; height: 1.1rem; }
.admin .filters { display: flex; gap: 1rem; flex-wrap: wrap; align-items: end; margin-bottom: 1.1rem; }
.admin .filters .field { flex: 1 1 15rem; }
.admin .pager { display: flex; justify-content: space-between; align-items: center; gap: 1rem; margin-top: 1rem; flex-wrap: wrap; }

/* Doubled class: must win over FormField's own styles for the public pages. */
.admin .field.field label { margin-bottom: .3rem; }
.admin .field.field input:not([type="file"]), .admin .field.field select { min-height: 3.1rem; border: 2px solid var(--color-field); border-radius: 8px; padding: .4rem .9rem; }
.admin .field.field input::placeholder { color: var(--color-muted); opacity: 1; }
.admin .field.field input[readonly] { background: var(--color-canvas); }
.admin .field.field.bad input, .admin .field.field.bad select { border-color: var(--color-danger); }
.admin .field.field .err { font-size: 1rem; font-weight: 600; }
.admin .err { color: var(--color-danger); font-weight: 600; }
.admin .formgrid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 1.2rem; }
.admin .full { grid-column: 1 / -1; }
.admin .label { font-weight: 600; margin-bottom: .3rem; }

.admin .meals { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .8rem; margin-bottom: 1.2rem; }
.admin .meals legend { font-weight: 650; margin-bottom: .5rem; }
.admin .meals button, .admin .seg button { min-height: 4.2rem; padding: .4rem; border: 2px solid var(--color-field); border-radius: 10px; background: #fff; font-weight: 700; font-size: 1.1rem; display: flex; gap: .7rem; align-items: center; justify-content: center; }
.admin .meals [aria-pressed="true"], .admin .seg [aria-pressed="true"] { background: var(--color-navy-900); border-color: var(--color-navy-900); color: #fff; }
.admin .seg { display: grid; grid-template-columns: 1fr 1fr; gap: .8rem; }
.admin .seg legend { font-weight: 600; margin-bottom: .3rem; }
.admin .seg button { min-height: 3.4rem; }
.admin .sum div { display: flex; justify-content: space-between; gap: 1rem; padding: .6rem 0; border-bottom: 1px solid var(--color-line); font-variant-numeric: tabular-nums; }
.admin .sum .tot { font-weight: 750; font-size: 1.35rem; border-bottom: 3px solid var(--color-ink); }
.admin .after { background: var(--color-paper); border-radius: 8px; padding: 1rem; font-weight: 650; font-size: 1.1rem; }
.admin .rows li { display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; padding: .7rem 0; border-bottom: 1px solid var(--color-line); }
.admin .rows li:last-child { border: 0; }
.admin .cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 17rem), 1fr)); gap: 1.2rem; }
.admin .cards .panel { display: flex; flex-direction: column; gap: .7rem; }
.admin .cards .acts { justify-content: flex-start; margin-top: auto; }
.admin .price { font-weight: 800; font-stretch: 75%; font-size: 2.6rem; line-height: 1; }

.admin .note-spot { position: sticky; top: .5rem; z-index: 2; }
.admin .note { display: flex; align-items: center; gap: .8rem; background: var(--color-ok-bg); border: 2px solid var(--color-ok); color: #064e3b; border-radius: 10px; padding: .7rem 1rem; margin-bottom: 1.2rem; font-weight: 600; overflow-wrap: anywhere; }
.admin .note button { margin-left: auto; min-height: 2.8rem; padding: 0 1rem; border: 2px solid currentColor; border-radius: 8px; background: #fff; font-weight: 650; }
.admin .note.error { background: #fdecec; border-color: var(--color-danger); color: #7f1d1d; }

.admin dialog { margin: auto; border-radius: 12px; padding: 1.8rem; max-width: 30rem; width: calc(100% - 2rem); color: var(--color-ink); }
.admin dialog::backdrop { background: #071330b8; }
.admin dialog p { margin: .7rem 0 1.4rem; overflow-wrap: anywhere; }

@container admin (max-width: 55.5rem) {
    .admin .app { grid-template-columns: minmax(0, 1fr); align-content: start; }
    .admin .bar { position: static; height: auto; padding: .7rem; }
    .admin .bar nav { display: grid; grid-template-columns: repeat(auto-fit, minmax(5.2rem, 1fr)); }
    .admin .brand { padding: .3rem; }
    .admin .bar .end { padding-top: .3rem; }
    .admin .bar nav a { flex-direction: column; gap: .15rem; justify-content: center; text-align: center; line-height: 1.1; padding: .5rem .2rem; }
    .admin .main { padding: 1rem; }
    .admin .two, .admin .formgrid { grid-template-columns: minmax(0, 1fr); }
}
/* Below this the row buttons no longer fit beside the columns. */
@container admin (max-width: 70rem) {
    .admin table, .admin tbody, .admin tr, .admin td { display: block; }
    .admin thead { display: flex; flex-wrap: wrap; background: var(--color-paper); }
    .admin thead tr { display: flex; flex-wrap: wrap; }
    .admin thead th { display: none; }
    .admin thead th[aria-sort] { display: block; }
    .admin tbody tr { border-top: 8px solid var(--color-canvas); padding: .6rem 0; }
    .admin td { border: 0; padding: .35rem 1rem; }
    .admin td[data-l]::before { content: attr(data-l) ": "; color: var(--color-muted); }
    .admin td .acts { justify-content: flex-start; flex-wrap: wrap; }
}
@container admin (max-width: 39rem) {
    .admin .acts .btn { flex: 1 1 auto; }
    .admin .meals { grid-template-columns: minmax(0, 1fr); }
    .admin .meals button { min-height: 3.3rem; }
    .admin h1 { font-size: 2rem; }
    .admin .head .acts, .admin .head .btn { width: 100%; }
}
@media (prefers-reduced-motion: reduce) {
    .admin * { transition: none !important; }
}
</style>
