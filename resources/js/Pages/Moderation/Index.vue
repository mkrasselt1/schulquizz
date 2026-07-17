<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/ui/Card.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';

interface Term {
    id: number;
    term: string;
    match_type: string;
}

const props = defineProps<{ terms: Term[]; baseCount: number }>();
const { t } = useI18n();

const form = useForm({ term: '', match_type: 'substring' });

function add() {
    form.post(route('moderation.store'), {
        preserveScroll: true,
        onSuccess: () => form.reset('term'),
    });
}
function remove(term: Term) {
    router.delete(route('moderation.destroy', term.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('moderation.title')" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">{{ t('moderation.title') }}</h2>
        </template>

        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
            <p class="mb-6 text-sm text-gray-500 dark:text-gray-400">
                {{ t('moderation.intro', { count: baseCount }) }}
            </p>

            <Card :title="t('moderation.yourTerms')">
                <form @submit.prevent="add" class="mb-5 flex gap-2">
                    <TextInput v-model="form.term" class="flex-1" :placeholder="t('moderation.term')" required />
                    <select
                        v-model="form.match_type"
                        class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    >
                        <option value="substring">{{ t('moderation.substring') }}</option>
                        <option value="exact">{{ t('moderation.exact') }}</option>
                    </select>
                    <PrimaryButton :disabled="form.processing">{{ t('moderation.add') }}</PrimaryButton>
                </form>

                <p v-if="terms.length === 0" class="text-sm text-gray-400">{{ t('moderation.empty') }}</p>
                <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700">
                    <li v-for="term in terms" :key="term.id" class="flex items-center justify-between py-2">
                        <span>
                            <span class="font-medium">{{ term.term }}</span>
                            <span class="ml-2 text-xs text-gray-400">{{ t('moderation.' + term.match_type) }}</span>
                        </span>
                        <button type="button" class="text-red-500" @click="remove(term)">✕</button>
                    </li>
                </ul>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
