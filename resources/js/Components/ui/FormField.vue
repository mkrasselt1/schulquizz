<script setup lang="ts">
defineProps<{
    label?: string;
    hint?: string;
    error?: string;
}>();
</script>

<template>
    <div>
        <label v-if="label" class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
            {{ label }}
        </label>
        <slot />
        <p v-if="hint" class="mt-1 text-xs text-gray-400">{{ hint }}</p>
        <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
    </div>
</template>
