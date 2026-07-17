<script setup lang="ts">
import { useTheme } from '@/Composables/useTheme';

const { isDark, toggle } = useTheme();
</script>

<template>
    <button
        type="button"
        @click="toggle"
        class="inline-flex h-9 w-9 items-center justify-center rounded-md text-lg text-gray-500 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700"
        :aria-label="isDark ? 'Light Mode' : 'Dark Mode'"
        :title="isDark ? 'Light Mode' : 'Dark Mode'"
    >
        <span v-if="isDark">☀️</span>
        <span v-else>🌙</span>
    </button>
</template>
