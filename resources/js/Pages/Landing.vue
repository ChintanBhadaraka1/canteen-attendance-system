<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MealCoupon from '../Components/MealCoupon.vue';
import StudentMenu from '../Components/StudentMenu.vue';
import PublicLayout from '../Layouts/PublicLayout.vue';

defineProps({
    meals: { type: Array, required: true },
    menu: { type: Array, required: true },
});
</script>

<template>
    <Head title="Canteen Attendance" />
    <PublicLayout>
        <main>
            <section class="wrap hero">
                <h1>Every meal counted, every rupee clear</h1>
                <p class="lead">Mark who ate, and the bill adds itself up. Students pay by cash or UPI and get a receipt.</p>
                <template v-if="meals.length">
                    <MealCoupon :meals="meals" />
                    <p class="hint">Punch a meal to see the bill change.</p>
                </template>
            </section>
            <section v-if="menu.length" class="wrap todays">
                <h2>Today's menu</h2>
                <StudentMenu :meals="menu" />
            </section>
            <section class="flow">
                <div class="wrap">
                    <h2>From the counter to the receipt</h2>
                    <ol>
                        <li><b>Mark the meal</b>Choose breakfast, lunch or dinner and tap each student once.</li>
                        <li><b>Add extras</b>Extra items go on the same bill with their quantity.</li>
                        <li><b>Take payment</b>Record cash or UPI and download a PDF receipt.</li>
                    </ol>
                    <table class="ledger">
                        <caption>How a part payment works</caption>
                        <tbody>
                            <tr><td>Meals this period</td><td>₹200</td></tr>
                            <tr><td>Paid by UPI</td><td>− ₹150</td></tr>
                            <tr><td>Carried to the next bill</td><td>₹50</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
            <section class="wrap who">
                <div>
                    <h2>Run the counter</h2>
                    <p>Students, menu, prices, attendance and bills in one place, with CSV exports for your records.</p>
                    <Link v-if="!$page.props.auth.user" class="btn" href="/login">Sign in</Link>
                </div>
                <div>
                    <h2>Eat here regularly?</h2>
                    <p>Register your details, then collect your canteen ID at the counter.</p>
                    <Link v-if="!$page.props.auth.user" class="btn" href="/register">Register</Link>
                </div>
            </section>
        </main>
        <footer class="wrap foot">Canteen Attendance</footer>
    </PublicLayout>
</template>

<style scoped>
.hero { padding-block: 34px 84px; }
.hero h1 {
    font-weight: 800;
    font-stretch: 75%;
    font-size: clamp(2.9rem, 7.6vw, 6.2rem);
    line-height: .94;
    letter-spacing: -.015em;
    max-width: 20ch;
}
.lead { max-width: 46ch; color: var(--color-navy-text); margin: 20px 0 40px; font-size: 1.2rem; }
.hint { margin-top: 16px; color: var(--color-navy-text); }
.todays { padding-bottom: 84px; }
.todays h2 { font-weight: 750; font-stretch: 80%; font-size: clamp(2rem, 4vw, 3.1rem); margin-bottom: 24px; }
.flow { background: #f4f7fc; color: var(--color-ink); padding-block: 84px; }
.flow h2, .who h2 {
    font-weight: 750;
    font-stretch: 80%;
    font-size: clamp(2rem, 4vw, 3.1rem);
    line-height: 1.05;
    max-width: 20ch;
}
.flow ol { margin-top: 44px; display: grid; grid-template-columns: 1.25fr 1fr 1fr; gap: 44px; }
.flow li { border-top: 3px solid var(--color-primary); padding-top: 18px; color: var(--color-muted); }
.flow li b { display: block; font-size: 1.35rem; margin-bottom: 6px; color: var(--color-ink); }
.ledger { margin-top: 56px; max-width: 600px; width: 100%; font-variant-numeric: tabular-nums; }
.ledger caption { text-align: left; font-weight: 650; padding-bottom: 8px; }
.ledger td { padding: 10px 0; border-bottom: 1px solid #c5d3ea; }
.ledger td:last-child { text-align: right; }
.ledger tr:last-child td { font-weight: 750; font-size: 1.25rem; border-bottom: 3px solid var(--color-ink); }
.who { padding-block: 84px; display: grid; grid-template-columns: 1fr 1fr; gap: 56px; }
.who p { color: var(--color-navy-text); max-width: 42ch; margin: 14px 0 24px; }
.foot { border-top: 1px solid var(--color-navy-700); padding-block: 26px; color: var(--color-navy-text); }
@media (max-width: 900px) {
    .flow ol, .who { grid-template-columns: 1fr; }
}
</style>
