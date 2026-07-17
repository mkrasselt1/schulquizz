<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import LocaleSwitcher from '@/Components/LocaleSwitcher.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';

defineProps<{ canRegister: boolean }>();

const { t } = useI18n();

const tiles = [
    { icon: '🙈', title: 'landing.tiles.noAccountsTitle', text: 'landing.tiles.noAccountsText' },
    { icon: '🗑️', title: 'landing.tiles.ephemeralTitle', text: 'landing.tiles.ephemeralText' },
    { icon: '🚫', title: 'landing.tiles.noTrackingTitle', text: 'landing.tiles.noTrackingText' },
    { icon: '🏫', title: 'landing.tiles.selfHostTitle', text: 'landing.tiles.selfHostText' },
];
</script>

<template>
    <Head :title="t('app.name')" />

    <div class="min-h-screen bg-gradient-to-b from-indigo-50 to-white dark:from-gray-900 dark:to-gray-800">
        <header class="mx-auto flex max-w-6xl items-center justify-between p-6">
            <div class="text-xl font-black text-indigo-700 dark:text-indigo-300">🎓 {{ t('app.name') }}</div>
            <div class="flex items-center gap-2">
                <ThemeToggle />
                <LocaleSwitcher />
                <Link :href="route('login')" class="text-sm font-medium text-gray-700 hover:underline dark:text-gray-200">
                    {{ t('nav.login') }}
                </Link>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 pb-20">
            <!-- Hero -->
            <section class="py-16 text-center">
                <h1 class="text-4xl font-black text-gray-900 sm:text-5xl dark:text-gray-100">
                    {{ t('landing.heroTitle') }}
                </h1>
                <p class="mx-auto mt-4 max-w-2xl text-lg text-gray-600 dark:text-gray-300">
                    {{ t('landing.heroSubtitle') }}
                </p>
                <div class="mt-8 flex flex-wrap justify-center gap-4">
                    <Link
                        :href="route('login')"
                        class="rounded-xl bg-indigo-600 px-6 py-3 font-semibold text-white shadow hover:bg-indigo-700"
                    >
                        {{ t('landing.forTeachers') }}
                    </Link>
                    <Link
                        :href="route('join.pin')"
                        class="rounded-xl bg-white px-6 py-3 font-semibold text-indigo-700 shadow ring-1 ring-indigo-200 hover:bg-indigo-50 dark:bg-gray-800 dark:text-indigo-200 dark:ring-gray-700"
                    >
                        {{ t('landing.forStudents') }}
                    </Link>
                </div>
            </section>

            <!-- Datenschutz-Kacheln -->
            <section>
                <h2 class="mb-8 text-center text-2xl font-bold text-gray-800 dark:text-gray-100">
                    {{ t('landing.privacyTitle') }}
                </h2>
                <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="tile in tiles"
                        :key="tile.title"
                        class="rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-gray-100 dark:bg-gray-800 dark:ring-gray-700"
                    >
                        <div class="mb-3 text-4xl">{{ tile.icon }}</div>
                        <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ t(tile.title) }}</h3>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ t(tile.text) }}</p>
                    </div>
                </div>
            </section>
        </main>
    </div>
</template>
