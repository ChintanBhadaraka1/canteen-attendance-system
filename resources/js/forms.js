import { nextTick } from 'vue';

export async function focusFirstError() {
    await nextTick();
    document.querySelector('[aria-invalid="true"]')?.focus();
}
