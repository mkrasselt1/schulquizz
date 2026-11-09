<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { setLocale, SUPPORTED_LOCALES, type AppLocale } from '@/i18n';

const { locale } = useI18n();

const options: Record<AppLocale, { flag: string; label: string }> = {
    de: { flag: '🇩🇪', label: 'Deutsch' },
    en: { flag: '🇬🇧', label: 'English' },
};

const current = computed(() => options[locale.value as AppLocale] ?? options.de);

const open = ref(false);
const root = ref<HTMLElement | null>(null);

function choose(loc: AppLocale) {
    setLocale(loc);
    open.value = false;
}

function onClickOutside(e: MouseEvent) {
    if (root.value && !root.value.contains(e.target as Node)) {
        open.value = false;
    }
}
onMounted(() => document.addEventListener('click', onClickOutside));
onBeforeUnmount(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            @click.stop="open = !open"
            :aria-label="$t('common.language')"
            class="flex items-center gap-1 rounded-md border border-gray-300 px-2 py-1.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
        >
            <span class="text-base leading-none">{{ current.flag }}</span>
            <svg class="h-3.5 w-3.5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                <path
                    fill-rule="evenodd"
                    d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z"
                    clip-rule="evenodd"
                />
            </svg>
        </button>

        <div
            v-if="open"
            class="absolute right-0 z-30 mt-1 w-40 overflow-hidden rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 dark:bg-gray-800"
        >
            <button
                v-for="loc in SUPPORTED_LOCALES"
                :key="loc"
                type="button"
                @click="choose(loc)"
                class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-gray-700"
                :class="
                    loc === locale
                        ? 'font-semibold text-indigo-600 dark:text-indigo-300'
                        : 'text-gray-700 dark:text-gray-200'
                "
            >
                <span class="text-base leading-none">{{ options[loc].flag }}</span>
                {{ options[loc].label }}
            </button>
        </div>
    </div>
</template>
