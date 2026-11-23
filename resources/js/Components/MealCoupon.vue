<script setup>
import { computed, ref } from 'vue';

const props = defineProps({ meals: { type: Array, required: true } });

const punched = ref(new Set(props.meals.slice(0, 2).map((meal) => meal.id)));
const total = computed(() => props.meals.reduce((sum, meal) => sum + (punched.value.has(meal.id) ? Number(meal.price) : 0), 0));
const rupees = (amount) => '₹' + Number(amount).toLocaleString('en-IN', { maximumFractionDigits: 2 });

function toggle(id) {
    if (!punched.value.delete(id)) punched.value.add(id);
}
</script>

<template>
    <div
        class="coupon"
        :class="{ many: meals.length > 3 }"
        :style="{ '--columns': Math.min(meals.length, 3) }"
        role="group"
        aria-label="Try a day of meals"
    >
        <button
            v-for="meal in meals"
            :key="meal.id"
            type="button"
            class="meal"
            :aria-pressed="punched.has(meal.id)"
            @click="toggle(meal.id)"
        >
            <strong>{{ meal.name }}</strong>
            <span class="price">{{ rupees(meal.price) }}</span>
            <span class="punch"></span>
        </button>
        <div class="stub">
            <span>Today's bill</span>
            <output aria-live="polite">{{ rupees(total) }}</output>
            <small>{{ punched.size }} {{ punched.size === 1 ? 'meal' : 'meals' }}</small>
        </div>
    </div>
</template>

<style scoped>
.coupon {
    --cut: 14px;
    --notch: calc(100% - 260px);
    display: grid;
    grid-template-columns: repeat(var(--columns), minmax(0, 1fr)) 260px;
    background: var(--color-paper);
    color: var(--color-ink);
    border-radius: 10px;
    mask:
        radial-gradient(circle var(--cut) at var(--notch) 0, #0000 98%, #000) top / 100% 51% no-repeat,
        radial-gradient(circle var(--cut) at var(--notch) 100%, #0000 98%, #000) bottom / 100% 51% no-repeat;
}
.meal {
    text-align: left;
    padding: 30px 28px;
    cursor: pointer;
    border-right: 1px solid #b3c6e8;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-height: 250px;
}
.meal strong { font-size: 1.5rem; font-weight: 700; overflow-wrap: anywhere; }
.price { font-size: 1.2rem; color: var(--color-muted); }
.punch {
    margin-top: auto;
    flex-shrink: 0;
    width: 68px;
    height: 68px;
    border-radius: 50%;
    border: 2px dashed #56719f;
    transition: background .18s, transform .18s;
}
.meal[aria-pressed='true'] .punch {
    background: var(--color-navy-900);
    border: 0;
    box-shadow: inset 0 5px 7px #0009;
    transform: scale(1.06);
}
.coupon .meal:focus-visible { outline-color: var(--color-primary); outline-offset: -6px; }
.stub { border-left: 2px dashed #56719f; padding: 30px 28px; display: flex; flex-direction: column; min-width: 0; }
.stub output {
    font-weight: 800;
    font-stretch: 75%;
    font-size: 4.6rem;
    line-height: 1;
    margin-top: auto;
    overflow-wrap: anywhere;
}
.stub small { font-size: 1rem; color: var(--color-muted); }
.many { grid-template-columns: repeat(3, minmax(0, 1fr)); mask: none; }
.many .meal { border-bottom: 1px solid #b3c6e8; }
.many .stub { grid-column: 1 / -1; border-left: 0; border-top: 2px dashed #56719f; }
@media (max-width: 900px) {
    .coupon { grid-template-columns: minmax(0, 1fr); mask: none; }
    .meal {
        min-height: 0;
        flex-direction: row;
        align-items: center;
        gap: 12px;
        border-right: 0;
        border-bottom: 1px solid #b3c6e8;
        padding: 18px 20px;
    }
    .punch { margin: 0 0 0 auto; width: 48px; height: 48px; }
    .stub { border-left: 0; border-top: 2px dashed #56719f; padding: 22px 20px; }
    .stub output { margin-top: 4px; }
}
</style>
