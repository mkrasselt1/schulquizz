<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps<{
    mcpUrl: string;
    apps: { client_id: string; name: string; since: string | null }[];
}>();

const { t } = useI18n();
const copied = ref(false);

function copy() {
    navigator.clipboard?.writeText(props.mcpUrl).then(() => {
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    });
}

function revoke(clientId: string, name: string) {
    if (confirm(t('mcp.confirmRevoke', { name }))) {
        router.delete(route('profile.apps.revoke', clientId), { preserveScroll: true });
    }
}
</script>

<template>
    <section class="space-y-4">
        <header>
            <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">🤖 {{ t('mcp.title') }}</h2>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ t('mcp.intro') }}</p>
        </header>

        <div>
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('mcp.address') }}</p>
            <div class="mt-1 flex items-center gap-2">
                <code class="flex-1 break-all rounded bg-gray-100 px-2 py-1.5 text-sm text-gray-800 dark:bg-gray-900 dark:text-gray-200">{{ mcpUrl }}</code>
                <SecondaryButton type="button" @click="copy">{{ copied ? '✓' : t('mcp.copy') }}</SecondaryButton>
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ t('mcp.howTo') }}</p>
        </div>

        <div>
            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('mcp.connected') }}</p>
            <p v-if="apps.length === 0" class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ t('mcp.none') }}</p>
            <ul v-else class="mt-2 divide-y divide-gray-100 dark:divide-gray-700">
                <li v-for="a in apps" :key="a.client_id" class="flex items-center justify-between gap-3 py-2">
                    <div class="text-sm text-gray-800 dark:text-gray-200">
                        {{ a.name }}
                        <span v-if="a.since" class="block text-xs text-gray-500">
                            {{ t('mcp.since') }} {{ new Date(a.since).toLocaleDateString() }}
                        </span>
                    </div>
                    <DangerButton type="button" @click="revoke(a.client_id, a.name)">{{ t('mcp.revoke') }}</DangerButton>
                </li>
            </ul>
        </div>
    </section>
</template>
