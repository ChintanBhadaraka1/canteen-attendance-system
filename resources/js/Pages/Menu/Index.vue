<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DataList from '../../Components/DataList.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { money } from '../../format';

defineProps({
    items: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const dialog = ref(null);
const chosen = ref(null);
const busy = ref(false);

function ask(item) {
    chosen.value = item;
    dialog.value.open();
}
const remove = () => router.delete(`/menus/${chosen.value.id}`, {
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        dialog.value?.close();
    },
});
</script>

<template>
    <AdminLayout title="Menu">
        <template #actions><AppButton :icon="Plus" href="/menus/create">Add menu item</AppButton></template>
        <DataList :rows="items" :filters="filters" noun="menu item" search-label="Find a menu item" search-hint="Name">
            <template #rows="{ rows }">
                <div class="cards">
                    <div v-for="item in rows" :key="item.id" class="panel">
                        <img
                            v-if="item.images" class="photo" :src="`/image/menus/${encodeURIComponent(item.images)}`" alt="" loading="lazy"
                            @error="$event.target.hidden = true"
                        >
                        <h2>{{ item.name }}</h2>
                        <span v-if="item.is_extra"><span class="tag due">Extra {{ money(item.price) }}</span></span>
                        <span v-else class="small">Regular item</span>
                        <div class="acts">
                            <AppButton variant="plain" :icon="Pencil" :href="`/menus/${item.id}/edit`">Edit<span class="sr-only"> {{ item.name }}</span></AppButton>
                            <AppButton variant="danger" :icon="Trash2" @click="ask(item)">Delete<span class="sr-only"> {{ item.name }}</span></AppButton>
                        </div>
                    </div>
                </div>
            </template>
        </DataList>
        <ConfirmDialog
            ref="dialog" :title="chosen ? `Delete ${chosen.name}?` : ''" text="It will no longer appear in the menu."
            confirm-label="Delete item" cancel-label="Keep item" :icon="Trash2" :busy="busy" @confirm="remove"
        />
    </AdminLayout>
</template>

<style scoped>
.photo { width: 100%; height: 9rem; object-fit: cover; border-radius: 8px; }
.photo[hidden] { display: none; }
</style>
