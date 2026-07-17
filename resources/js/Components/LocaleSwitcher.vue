<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { setLocale, SUPPORTED_LOCALES, type AppLocale } from '@/i18n';

const { locale } = useI18n();

function change(event: Event) {
    const value = (event.target as HTMLSelectElement).value as AppLocale;
    setLocale(value);
}

const labels: Record<AppLocale, string> = {
    de: 'Deutsch',
    en: 'English',
};
</script>

<template>
    <select
        :value="locale"
        @change="change"
        class="rounded-md border-gray-300 py-1 pl-2 pr-8 text-sm text-gray-700 focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200"
        :aria-label="$t('common.language')"
    >
        <option v-for="loc in SUPPORTED_LOCALES" :key="loc" :value="loc">
            {{ labels[loc] }}
        </option>
    </select>
</template>
