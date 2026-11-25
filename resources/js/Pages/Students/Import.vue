<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { CircleAlert, Download, FileUp, TriangleAlert, Upload, UserPlus, X } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';
import { displayName } from '../../format';

const props = defineProps({ preview: { type: Object, default: null } });

const form = useForm({ file: null });
const upload = () => form.post('/user/import', { forceFormData: true, onError: focusFirstError });

const busy = ref(false);
const during = { onStart: () => { busy.value = true; }, onFinish: () => { busy.value = false; } };
const save = () => router.post('/user/import/confirm', { count: props.preview.good }, during);
const discard = (after) => router.delete('/user/import', { ...during, onSuccess: after });
const another = () => discard(() => form.reset());
const cancel = () => discard(() => router.get('/user'));

const people = (count) => `${count} ${count === 1 ? 'student' : 'students'}`;
const title = computed(() => (props.preview ? 'Check the list' : 'Import students'));
</script>

<template>
    <AdminLayout :title="title" :back="{ href: '/user', label: 'Back to students' }">
        <form v-if="!preview" class="panel stack" novalidate @submit.prevent="upload">
            <ol class="steps">
                <li>Download the template and open it in Excel.</li>
                <li>Fill in one student per row. Format the phone and canteen ID columns as Text.</li>
                <li>Save as "CSV UTF-8" and choose the file here. At most 500 students, 1 MB.</li>
            </ol>
            <div><AppButton variant="plain" :icon="Download" href="/user/import/template" external>Download template</AppButton></div>
            <div>
                <label class="label" for="file">File</label>
                <input
                    id="file" class="file" type="file" accept=".csv,.txt" :aria-invalid="form.errors.file ? 'true' : undefined"
                    :aria-describedby="form.errors.file ? 'file-error' : undefined" @change="form.file = $event.target.files[0] ?? null"
                >
                <p v-if="form.errors.file" id="file-error" class="err" role="alert">{{ form.errors.file }}</p>
            </div>
            <div class="acts start">
                <AppButton type="submit" :icon="Upload" :disabled="form.processing">Check this file</AppButton>
                <AppButton variant="plain" href="/user">Cancel</AppButton>
            </div>
        </form>
        <template v-else>
            <p class="after" role="status">{{ people(preview.good) }} can be added.<template v-if="preview.bad"> {{ preview.bad }} {{ preview.bad === 1 ? 'row has' : 'rows have' }} mistakes and will not be added.</template></p>
            <div class="acts start choices">
                <AppButton v-if="preview.good" :icon="UserPlus" :disabled="busy" @click="save">Save {{ people(preview.good) }}</AppButton>
                <AppButton variant="plain" :icon="FileUp" :disabled="busy" @click="another">Choose another file</AppButton>
                <AppButton variant="plain" :icon="X" :disabled="busy" @click="cancel">Cancel</AppButton>
            </div>
            <ul class="stack">
                <li v-for="row in preview.rows" :key="row.line" class="panel row">
                    <p><b>Line {{ row.line }}: {{ displayName(row.data) || 'No name' }}</b><br><span class="small">{{ [row.data.canteen_id, row.data.collage_name, row.data.email, row.data.phone_number].filter(Boolean).join(', ') }}</span></p>
                    <StatusTag v-if="!row.errors.length" kind="ok">Correct</StatusTag>
                    <p v-for="error in row.errors" :key="error" class="err note-line"><CircleAlert aria-hidden="true" />{{ error }}</p>
                    <p v-for="warning in row.warnings" :key="warning" class="note-line warn"><TriangleAlert aria-hidden="true" />{{ warning }}</p>
                </li>
            </ul>
        </template>
    </AdminLayout>
</template>

<style scoped>
.steps { list-style: decimal; padding-left: 1.5rem; display: grid; gap: .5rem; }
.file { width: 100%; min-height: 3.1rem; padding: .4rem; border: 2px solid var(--color-field); border-radius: 8px; background: #fff; }
.file::file-selector-button { min-height: 2.2rem; padding: 0 1rem; margin-right: .8rem; border-radius: 6px; background: var(--color-paper); font-weight: 650; cursor: pointer; }
.choices { margin: 1.2rem 0; }
.row { display: flex; align-items: center; justify-content: space-between; gap: .5rem 1rem; flex-wrap: wrap; overflow-wrap: anywhere; }
.row > p { min-width: 0; }
.note-line { flex-basis: 100%; display: flex; align-items: center; gap: .5rem; }
.warn { color: var(--color-due); font-weight: 600; }
</style>
