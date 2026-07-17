<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import LocaleSwitcher from '@/Components/LocaleSwitcher.vue';
import ThemeToggle from '@/Components/ThemeToggle.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import InputError from '@/Components/InputError.vue';

const props = defineProps<{
    mode: 'pin' | 'qr';
    joinToken?: string;
    gameTitle?: string;
    active: boolean;
}>();

const { t } = useI18n();

const isActive = ref(props.active);
let poll: ReturnType<typeof setInterval> | null = null;

// Reconnect-Daten aus vorheriger Teilnahme (localStorage).
const stored = JSON.parse(localStorage.getItem('schulquizz.participant') || 'null');

const form = useForm({
    pin: '',
    join_token: props.joinToken ?? '',
    display_type: 'name' as 'name' | 'emoji',
    name: stored?.kind === 'name' ? stored.label : '',
    participant_token: stored?.token ?? '',
    reconnect_emoji: stored?.kind === 'emoji' ? stored.label : '',
});

function submit() {
    form.transform((data) => ({
        ...data,
        // Bei Anonym kein Name mitsenden.
        name: data.display_type === 'name' ? data.name : '',
        reconnect_emoji: data.display_type === 'emoji' ? data.reconnect_emoji : '',
    })).post(route('join.submit'));
}

// Warteseite (QR ohne laufende Session): Status pollen.
onMounted(() => {
    if (props.mode === 'qr' && !isActive.value && props.joinToken) {
        poll = setInterval(async () => {
            const res = await axios.get(route('join.status', props.joinToken));
            if (res.data.active) {
                isActive.value = true;
                if (poll) clearInterval(poll);
            }
        }, 3000);
    }
});
onBeforeUnmount(() => {
    if (poll) clearInterval(poll);
});
</script>

<template>
    <Head :title="t('play.joinTitle')" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-b from-indigo-50 to-white p-6 dark:from-gray-900 dark:to-gray-800">
        <div class="absolute right-4 top-4 flex items-center gap-1">
            <ThemeToggle />
            <LocaleSwitcher />
        </div>

        <div class="w-full max-w-sm rounded-2xl bg-white p-8 shadow-lg dark:bg-gray-800">
            <h1 class="mb-1 text-center text-2xl font-bold text-gray-900 dark:text-gray-100">
                {{ gameTitle || t('play.joinTitle') }}
            </h1>

            <!-- Warteseite -->
            <div v-if="mode === 'qr' && !isActive" class="mt-6 text-center">
                <div class="mb-4 text-5xl">⏳</div>
                <p class="text-gray-700 dark:text-gray-300">{{ t('play.waiting') }}</p>
                <p class="mt-2 text-sm text-gray-400">{{ t('play.waitingHint') }}</p>
            </div>

            <!-- Beitritts-Formular -->
            <form v-else @submit.prevent="submit" class="mt-6 space-y-5">
                <div v-if="mode === 'pin'">
                    <label class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ t('play.enterPin') }}
                    </label>
                    <input
                        v-model="form.pin"
                        inputmode="numeric"
                        maxlength="6"
                        class="block w-full rounded-lg border-gray-300 text-center text-2xl tracking-[0.4em] dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        placeholder="000000"
                    />
                    <InputError :message="form.errors.pin" class="mt-1" />
                </div>

                <div>
                    <span class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ t('play.chooseName') }}
                    </span>
                    <div class="flex gap-2">
                        <button
                            type="button"
                            class="flex-1 rounded-lg border px-3 py-2 text-sm"
                            :class="form.display_type === 'name'
                                ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200'
                                : 'border-gray-300 dark:border-gray-600'"
                            @click="form.display_type = 'name'"
                        >
                            {{ t('play.withName') }}
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-lg border px-3 py-2 text-sm"
                            :class="form.display_type === 'emoji'
                                ? 'border-indigo-500 bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-200'
                                : 'border-gray-300 dark:border-gray-600'"
                            @click="form.display_type = 'emoji'"
                        >
                            {{ t('play.anonymous') }}
                        </button>
                    </div>
                </div>

                <div v-if="form.display_type === 'name'">
                    <input
                        v-model="form.name"
                        maxlength="30"
                        class="block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        :placeholder="t('play.yourName')"
                    />
                    <InputError :message="form.errors.name" class="mt-1" />
                </div>

                <PrimaryButton class="w-full justify-center" :disabled="form.processing">
                    {{ t('play.joinNow') }}
                </PrimaryButton>
            </form>
        </div>
    </div>
</template>
