<script setup>
import { onBeforeUnmount, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import ConfirmDialog from './ConfirmDialog.vue';

const props = defineProps({
    active: Boolean,
    text: { type: String, required: true },
    // Saving under this path does not ask.
    saves: { type: String, default: '/student-attendance' },
});
const emit = defineEmits(['stay']);

const dialog = ref(null);
let waiting = null;
let allowed = false;

// shortcut: the browser's Back button cannot be stopped here, upgrade if Inertia makes it cancellable.
const stop = router.on('before', ({ detail: { visit } }) => {
    const saving = visit.method !== 'get' && visit.url.pathname.startsWith(props.saves);
    if (!props.active || allowed || saving) return;
    waiting = visit;
    dialog.value.open();
    return false;
});
function warnBrowser(event) {
    if (props.active) event.preventDefault();
}
window.addEventListener('beforeunload', warnBrowser);
onBeforeUnmount(() => {
    stop();
    window.removeEventListener('beforeunload', warnBrowser);
});

function leave() {
    dialog.value.close();
    const { url, method, data, preserveState, preserveScroll } = waiting;
    allowed = true;
    router.visit(url.href, {
        method,
        data: method === 'get' ? {} : data,
        preserveState,
        preserveScroll,
        onFinish: () => { allowed = false; },
    });
}
</script>

<template>
    <ConfirmDialog
        ref="dialog" title="Leave without saving?" :text="text" confirm-label="Leave" cancel-label="Stay"
        @confirm="leave" @closed="emit('stay')"
    />
</template>
