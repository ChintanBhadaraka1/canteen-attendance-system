<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    variant: { type: String, default: 'primary' },
    icon: { type: [Object, Function], default: undefined },
    href: { type: String, default: undefined },
    external: Boolean,
    disabled: Boolean,
});
</script>

<template>
    <Link v-if="href && !external" :href="href" class="btn" :class="variant">
        <component :is="icon" v-if="icon" aria-hidden="true" /><slot />
    </Link>
    <a v-else-if="href" :href="href" class="btn" :class="variant">
        <component :is="icon" v-if="icon" aria-hidden="true" /><slot />
    </a>
    <button v-else type="button" class="btn" :class="variant" :disabled="disabled">
        <component :is="icon" v-if="icon" aria-hidden="true" /><slot />
    </button>
</template>
