import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

// Reloads the given props when form.date changes. Block saving while loading: prices belong to a date.
export function useDatePrices(form, props, today, only) {
    const failed = ref(false);
    const valid = computed(() => /^\d{4}-\d{2}-\d{2}$/.test(form.date) && form.date <= today);
    const loading = computed(() => valid.value && form.date !== props.date);
    let timer = null;
    function load() {
        clearTimeout(timer);
        failed.value = false;
        timer = setTimeout(() => {
            if (!loading.value) return;
            router.reload({
                data: { date: form.date },
                only,
                replace: true,
                onHttpException: () => false,
                onNetworkError: () => false,
                onFinish: () => { failed.value = loading.value; },
            });
        }, 250);
    }
    watch(() => form.date, load);
    onBeforeUnmount(() => clearTimeout(timer));

    return { loading, failed, load };
}
