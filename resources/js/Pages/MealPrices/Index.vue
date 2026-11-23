<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { money } from '../../format';

defineProps({ meals: { type: Array, required: true } });

const dialog = ref(null);
const chosen = ref(null);
const busy = ref(false);

function ask(meal) {
    chosen.value = meal;
    dialog.value.open();
}
const remove = () => router.delete(`/meal-price/${chosen.value.id}`, {
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        dialog.value?.close();
    },
});
</script>

<template>
    <AdminLayout title="Meal prices">
        <template #actions><AppButton :icon="Plus" href="/meal-price/create">Add meal</AppButton></template>
        <p v-if="!meals.length" class="panel">No meals yet. Add the first meal and its price.</p>
        <div class="cards">
            <div v-for="meal in meals" :key="meal.id" class="panel">
                <h2>{{ meal.name }}</h2>
                <span class="price">{{ money(meal.price) }}</span>
                <div class="acts">
                    <AppButton variant="plain" :icon="Pencil" :href="`/meal-price/${meal.id}/edit`">Change price<span class="sr-only"> of {{ meal.name }}</span></AppButton>
                    <AppButton variant="danger" :icon="Trash2" @click="ask(meal)">Delete<span class="sr-only"> {{ meal.name }}</span></AppButton>
                </div>
            </div>
        </div>
        <ConfirmDialog
            ref="dialog" :title="chosen ? `Delete ${chosen.name}?` : ''" text="Past attendance will no longer show this meal's name."
            confirm-label="Delete meal" cancel-label="Keep meal" :icon="Trash2" :busy="busy" @confirm="remove"
        />
    </AdminLayout>
</template>
