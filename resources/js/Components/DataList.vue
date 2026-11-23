<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, ArrowUpDown } from '@lucide/vue';
import AppButton from './AppButton.vue';
import FormField from './FormField.vue';

const props = defineProps({
    columns: { type: Array, default: () => [] },
    rows: { type: Object, required: true },
    filters: { type: Object, required: true },
    searchLabel: { type: String, required: true },
    searchHint: { type: String, default: undefined },
    noun: { type: String, required: true },
    plural: { type: String, default: undefined },
});

const search = ref(props.filters.search);
let timer = null;
let busy = false;

function visit(changes, options = {}) {
    clearTimeout(timer);
    const params = Object.entries({ ...props.filters, search: search.value.trim(), page: 1, ...changes })
        .filter(([key, value]) => value !== '' && value != null && !(key === 'page' && value === 1));

    router.get(window.location.pathname, Object.fromEntries(params), { preserveState: true, preserveScroll: true, ...options });
}
defineExpose({ visit });

function searchSoon() {
    clearTimeout(timer);
    // A search must not cut off a save that is still on its way.
    timer = setTimeout(() => (busy ? searchSoon() : visit({}, { replace: true })), 350);
}

const stopStart = router.on('start', () => { busy = true; });
const stopFinish = router.on('finish', () => { busy = false; });
onBeforeUnmount(() => {
    clearTimeout(timer);
    stopStart();
    stopFinish();
});

const sorted = (column) => props.filters.sort === column.key;
const sort = (column) =>
    visit({ sort: column.key, dir: sorted(column) && props.filters.dir === 'asc' ? 'desc' : 'asc' });
const sortIcon = (column) => (!sorted(column) ? ArrowUpDown : props.filters.dir === 'asc' ? ArrowUp : ArrowDown);
const countWord = () => (props.rows.total === 1 ? props.noun : props.plural ?? `${props.noun}s`);
</script>

<template>
    <div class="filters">
        <FormField
            id="list-search" v-model="search" :label="searchLabel" :placeholder="searchHint"
            type="search" maxlength="100" autocomplete="off" @input="searchSoon"
        />
        <slot name="filters" :visit="visit" />
    </div>

    <div v-if="!rows.data.length" class="panel">
        <slot name="empty">{{ filters.search ? 'Nothing found. Check the spelling or clear the search.' : `No ${plural ?? `${noun}s`} to show.` }}</slot>
    </div>
    <slot v-else name="rows" :rows="rows.data">
        <!-- Roles keep the table readable to screen readers when it is stacked on a phone. -->
        <table role="table">
            <thead role="rowgroup">
                <tr role="row">
                    <th
                        v-for="column in columns" :key="column.key" role="columnheader" scope="col"
                        :aria-sort="!column.sortable ? undefined : !sorted(column) ? 'none' : filters.dir === 'asc' ? 'ascending' : 'descending'"
                    >
                        <button v-if="column.sortable" type="button" class="sort" @click="sort(column)">
                            {{ column.label }}<component :is="sortIcon(column)" aria-hidden="true" />
                        </button>
                        <template v-else>{{ column.label }}</template>
                    </th>
                    <th v-if="$slots.actions" role="columnheader" scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                <tr v-for="row in rows.data" :key="row.id" role="row">
                    <td v-for="(column, index) in columns" :key="column.key" role="cell" :data-l="index ? column.label : undefined">
                        <slot :name="`cell-${column.key}`" :row="row">{{ row[column.key] || 'Not given' }}</slot>
                    </td>
                    <td v-if="$slots.actions" role="cell"><div class="acts"><slot name="actions" :row="row" /></div></td>
                </tr>
            </tbody>
        </table>
    </slot>

    <div class="pager">
        <span role="status">
            <template v-if="rows.total">Showing {{ rows.from }} to {{ rows.to }} of {{ rows.total }} {{ countWord() }}</template>
            <template v-else>No {{ plural ?? `${noun}s` }} to show</template>
        </span>
        <div v-if="rows.last_page > 1" class="acts">
            <AppButton variant="plain" :icon="ArrowLeft" :disabled="rows.current_page === 1" @click="visit({ page: rows.current_page - 1 })">Previous</AppButton>
            <AppButton variant="plain" :icon="ArrowRight" :disabled="rows.current_page === rows.last_page" @click="visit({ page: rows.current_page + 1 })">Next</AppButton>
        </div>
    </div>
</template>
