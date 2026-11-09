<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/ui/Card.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatClock } from '@/types/escape';

const { t } = useI18n();

const text = ref('');
const name = ref('');
const checking = ref(false);
const result = ref<any>(null);

const inputClass =
    'block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';

async function check() {
    if (!text.value.trim()) return;
    checking.value = true;
    try {
        const res = await axios.post(route('escape.verify.check'), { text: text.value, name: name.value });
        result.value = res.data;
        // Name fehlte in der Nachricht → Feld anbieten
        if (res.data.reason === 'no_name' && !name.value) name.value = '';
    } catch {
        result.value = { valid: false, reason: 'error' };
    } finally {
        checking.value = false;
    }
}
</script>

<template>
    <Head :title="t('verify.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">✅ {{ t('verify.title') }}</h2>
        </template>

        <div class="mx-auto max-w-2xl space-y-6 px-4 py-8">
            <Card>
                <form class="space-y-4" @submit.prevent="check">
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ t('verify.intro') }}</p>
                    <textarea
                        v-model="text"
                        rows="6"
                        :class="inputClass"
                        :placeholder="t('verify.placeholder')"
                    ></textarea>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('verify.nameLabel') }}</label>
                        <input v-model="name" :class="inputClass" :placeholder="t('verify.namePlaceholder')" />
                    </div>
                    <PrimaryButton :disabled="checking || !text.trim()">{{ t('verify.check') }}</PrimaryButton>
                </form>
            </Card>

            <Card v-if="result">
                <div v-if="result.valid" class="space-y-2 text-gray-800 dark:text-gray-100">
                    <p class="text-2xl font-bold text-green-600">✅ {{ t('verify.valid') }}</p>
                    <p><span class="text-gray-500">{{ t('verify.name') }}:</span> <strong>{{ result.name }}</strong></p>
                    <p><span class="text-gray-500">{{ t('verify.game') }}:</span> {{ result.game_title }}</p>
                    <p><span class="text-gray-500">{{ t('verify.time') }}:</span> <span class="font-mono">{{ formatClock(result.elapsed_seconds) }}</span></p>
                    <p><span class="text-gray-500">{{ t('verify.finishedAt') }}:</span> {{ new Date(result.finished_at).toLocaleString() }}</p>
                </div>
                <div v-else class="space-y-1 text-gray-800 dark:text-gray-100">
                    <p class="text-2xl font-bold text-red-600">❌ {{ t('verify.invalid') }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">{{ t(`verify.reason.${result.reason}`) }}</p>
                </div>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
