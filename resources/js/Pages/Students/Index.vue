<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Eye, Pencil, Trash2, UserPlus } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import Avatar from '../../Components/Avatar.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import DataList from '../../Components/DataList.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { displayName } from '../../format';

defineProps({
    students: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const columns = [
    { key: 'name', label: 'Student', sortable: true },
    { key: 'canteen_id', label: 'Canteen ID', sortable: true },
    { key: 'collage_name', label: 'College', sortable: true },
];

const dialog = ref(null);
const chosen = ref(null);
const busy = ref(false);

function ask(student) {
    chosen.value = student;
    dialog.value.open();
}
const remove = () => router.delete(`/user/${chosen.value.id}`, {
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        dialog.value?.close();
    },
});
</script>

<template>
    <AdminLayout title="Students">
        <template #actions><AppButton :icon="UserPlus" href="/user/create">Add student</AppButton></template>
        <DataList
            :columns="columns" :rows="students" :filters="filters" noun="student"
            search-label="Find a student" search-hint="Name, email, canteen ID or college"
        >
            <template #cell-name="{ row }">
                <div class="who">
                    <Avatar :name="row.name" :photo="row.profile_pic" />
                    <span><b>{{ displayName(row) }}</b><br><span class="small">{{ row.email }}</span></span>
                </div>
            </template>
            <template #actions="{ row }">
                <AppButton variant="plain" :icon="Eye" :href="`/user/${row.id}`">View<span class="sr-only"> {{ displayName(row) }}</span></AppButton>
                <AppButton variant="plain" :icon="Pencil" :href="`/user/${row.id}/edit`">Edit<span class="sr-only"> {{ displayName(row) }}</span></AppButton>
                <AppButton variant="danger" :icon="Trash2" @click="ask(row)">Delete<span class="sr-only"> {{ displayName(row) }}</span></AppButton>
            </template>
        </DataList>
        <ConfirmDialog
            ref="dialog" :title="chosen ? `Delete ${displayName(chosen)}?` : ''" text="They will no longer appear in the student list."
            confirm-label="Delete student" cancel-label="Keep student" :icon="Trash2" :busy="busy" @confirm="remove"
        />
    </AdminLayout>
</template>
