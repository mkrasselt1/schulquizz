<script setup lang="ts">
withDefaults(
    defineProps<{
        title?: string;
        padded?: boolean;
    }>(),
    { padded: true },
);
</script>

<template>
    <div
        class="rounded-lg bg-white shadow-sm ring-1 ring-gray-100 dark:bg-gray-800 dark:ring-gray-700"
    >
        <div
            v-if="title || $slots.header"
            class="flex items-center justify-between border-b border-gray-100 px-5 py-3 dark:border-gray-700"
        >
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
                {{ title }}
                <slot name="header" />
            </h3>
            <slot name="actions" />
        </div>
        <div :class="padded ? 'p-5' : ''">
            <slot />
        </div>
    </div>
</template>
