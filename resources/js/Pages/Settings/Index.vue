<script setup>
import { ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, Check, CircleOff, Pencil, Plus, Trash2 } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import FormField from '../../Components/FormField.vue';
import StatusTag from '../../Components/StatusTag.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { focusFirstError } from '../../forms';

defineProps({ kinds: { type: Object, required: true } });

const sections = [
    { kind: 'food_type', title: 'Food types', word: 'food type' },
    { kind: 'category', title: 'Categories', word: 'category' },
    { kind: 'stock_category', title: 'Stock categories', word: 'stock category' },
    { kind: 'shop', title: 'Shops', word: 'shop' },
    { kind: 'expense_type', title: 'Expense types', word: 'expense type' },
    { kind: 'staff_job', title: 'Staff jobs', word: 'staff job' },
    { kind: 'waste_reason', title: 'Waste reasons', word: 'waste reason' },
];
const adding = Object.fromEntries(sections.map(({ kind }) => [kind, useForm({ kind, name: '' })]));
const add = (kind) => adding[kind].post('/settings/options', {
    preserveScroll: true, onSuccess: () => adding[kind].reset(), onError: focusFirstError,
});

const busy = ref(false);
const during = (dialog) => ({
    preserveScroll: true,
    onStart: () => { busy.value = true; },
    onFinish: () => {
        busy.value = false;
        dialog?.value?.close();
    },
});
const turn = (option) => router.put(`/settings/options/${option.id}`, { active: !option.active }, during());
const move = (option, direction) => router.post(`/settings/options/${option.id}/move`, { direction }, during());

const parts = [
    { key: 'expenses', title: 'Expenses', text: 'Rent, electricity, gas and other costs.' },
    { key: 'staff', title: 'Staff and salaries', text: 'Who works here and what they were paid.' },
    { key: 'cash', title: 'Cash check', text: 'Compare the cash box with the app.' },
];
const part = ref(null);
const hiding = ref(null);
const setPart = (key, on) => router.put('/settings/modules', { module: key, on }, during(hiding));
function turnPart(one, isOn) {
    part.value = one;
    if (isOn) hiding.value.open();
    else setPart(one.key, true);
}

const chosen = ref(null);
const removing = ref(null);
const renaming = ref(null);
const rename = useForm({ name: '' });
function ask(option, dialog) {
    chosen.value = option;
    rename.clearErrors();
    rename.name = option.name;
    dialog.value.open();
}
const remove = () => router.delete(`/settings/options/${chosen.value.id}`, during(removing));
const saveName = () => rename.put(`/settings/options/${chosen.value.id}`, {
    preserveScroll: true, onSuccess: () => renaming.value.close(), onError: focusFirstError,
});
</script>

<template>
    <AdminLayout title="Settings">
        <div class="stack">
            <section class="panel">
                <h2>Parts of the app</h2>
                <ul class="rows">
                    <li v-for="one in parts" :key="one.key">
                        <span class="name"><b>{{ one.title }}</b> <span class="small">{{ one.text }}</span></span>
                        <button type="button" role="switch" class="switch" :aria-checked="$page.props.modules[one.key] ? 'true' : 'false'" :disabled="busy" @click="turnPart(one, $page.props.modules[one.key])">
                            <component :is="$page.props.modules[one.key] ? Check : CircleOff" aria-hidden="true" /><span class="sr-only">{{ one.title }}: </span>{{ $page.props.modules[one.key] ? 'On' : 'Off' }}
                        </button>
                    </li>
                </ul>
            </section>
            <section v-for="section in sections" :key="section.kind" class="panel">
                <h2>{{ section.title }}</h2>
                <p v-if="!kinds[section.kind].length" class="small">None yet.</p>
                <ul class="rows">
                    <li v-for="(option, index) in kinds[section.kind]" :key="option.id">
                        <span class="name"><b>{{ option.name }}</b> <StatusTag :kind="option.active ? 'ok' : 'due'">{{ option.active ? 'On' : 'Off' }}</StatusTag></span>
                        <span class="acts start">
                            <AppButton variant="plain" :icon="ArrowUp" :disabled="busy || index === 0" @click="move(option, 'up')">Up<span class="sr-only">: {{ option.name }}</span></AppButton>
                            <AppButton variant="plain" :icon="ArrowDown" :disabled="busy || index === kinds[section.kind].length - 1" @click="move(option, 'down')">Down<span class="sr-only">: {{ option.name }}</span></AppButton>
                            <AppButton variant="plain" :icon="Pencil" :disabled="busy" @click="ask(option, { value: renaming })">Rename<span class="sr-only"> {{ option.name }}</span></AppButton>
                            <AppButton variant="plain" :icon="option.active ? CircleOff : Check" :disabled="busy" @click="turn(option)">{{ option.active ? 'Turn off' : 'Turn on' }}<span class="sr-only"> {{ option.name }}</span></AppButton>
                            <AppButton v-if="!option.used" variant="danger" :icon="Trash2" :disabled="busy" @click="ask(option, { value: removing })">Delete<span class="sr-only"> {{ option.name }}</span></AppButton>
                            <span v-else class="small used">Used {{ option.used === 1 ? 'once' : `${option.used} times` }}</span>
                        </span>
                    </li>
                </ul>
                <form class="filters add" novalidate @submit.prevent="add(section.kind)">
                    <FormField :id="`new-${section.kind}`" v-model="adding[section.kind].name" :label="`New ${section.word}`" maxlength="30" autocomplete="off" :error="adding[section.kind].errors.name" />
                    <AppButton type="submit" :icon="Plus" :disabled="adding[section.kind].processing">Add {{ section.word }}</AppButton>
                </form>
            </section>
        </div>
        <ConfirmDialog
            ref="renaming" :title="chosen ? `Rename ${chosen.name}` : ''" confirm-label="Save name" cancel-label="Cancel" variant="primary"
            :icon="Check" :busy="rename.processing" @confirm="saveName"
        >
            <form class="field-space" novalidate @submit.prevent="saveName">
                <FormField id="rename" v-model="rename.name" label="Name" maxlength="30" autocomplete="off" :error="rename.errors.name" />
            </form>
        </ConfirmDialog>
        <ConfirmDialog
            ref="hiding" :title="part ? `Turn off ${part.title}?` : ''" text="Your records stay and can be shown again."
            confirm-label="Turn off" cancel-label="Keep it on" :icon="CircleOff" :busy="busy" @confirm="setPart(part.key, false)"
        />
        <ConfirmDialog
            ref="removing" :title="chosen ? `Delete ${chosen.name}?` : ''" text="This cannot be undone."
            confirm-label="Delete" cancel-label="Keep it" :icon="Trash2" :busy="busy" @confirm="remove"
        />
    </AdminLayout>
</template>

<style scoped>
.name { display: flex; align-items: center; gap: .7rem; flex-wrap: wrap; min-width: 0; overflow-wrap: anywhere; }
.used { align-self: center; padding: 0 .5rem; }
.switch { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-width: 7rem; min-height: 3.1rem; border: 2px solid var(--color-field); border-radius: 8px; background: #fff; font-weight: 650; cursor: pointer; }
.switch[aria-checked="true"] { background: var(--color-primary); border-color: var(--color-primary); color: #fff; }
.add { margin: 1rem 0 0; }
.field-space { margin-bottom: 1.4rem; }
</style>
