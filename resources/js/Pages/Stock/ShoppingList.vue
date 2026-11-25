<script setup>
import { Printer } from '@lucide/vue';
import AppButton from '../../Components/AppButton.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { longDate } from '../../format';
import { amountOf } from '../../stock';

defineProps({ items: { type: Array, required: true } });
const print = () => window.print();
</script>

<template>
    <AdminLayout title="Shopping list" :back="{ href: '/stock', label: 'Back to stock' }">
        <template #actions><AppButton :icon="Printer" @click="print">Print</AppButton></template>
        <p class="day">{{ longDate($page.props.today) }}</p>
        <p v-if="!items.length" class="panel">Nothing is low.</p>
        <table v-else role="table">
            <thead role="rowgroup"><tr role="row"><th role="columnheader" scope="col">Item</th><th role="columnheader" scope="col">In stock</th><th role="columnheader" scope="col">Low at</th></tr></thead>
            <tbody role="rowgroup">
                <tr v-for="item in items" :key="item.id" role="row">
                    <td role="cell"><b>{{ item.name }}</b><template v-if="item.category">{{ `, ${item.category}` }}</template></td>
                    <td role="cell" data-l="In stock">{{ amountOf(item.on_hand, item.unit) }}</td>
                    <td role="cell" data-l="Low at">{{ amountOf(item.low_level, item.unit) }}</td>
                </tr>
            </tbody>
        </table>
    </AdminLayout>
</template>

<style scoped>
.day { margin-bottom: 1rem; }
</style>
