<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import QrCode from '@/Components/QrCode.vue';
import Podium from '@/Components/Play/Podium.vue';
import ResultDistribution from '@/Components/Play/ResultDistribution.vue';
import { useCountdown } from '@/Composables/useCountdown';
import { tr, type PublicQuestion } from '@/types/quiz';
import { formatClock } from '@/types/escape';

interface State {
    mode?: 'quiz' | 'escape';
    status: string;
    phase: string | null;
    question_index: number | null;
    question_total: number;
    deadline: string | null;
    question: PublicQuestion | null;
}

const props = defineProps<{
    sessionKey: string;
    gameTitle: string;
    joinMethod: string;
    pin: string | null;
    joinUrl: string;
    state: State;
}>();

const { t, locale } = useI18n();
const state = reactive<State>({ ...props.state });
const distribution = ref<any>(null);
const podium = ref<any[] | null>(null);

const { remaining } = useCountdown(() => state.deadline);
const channelName = `board.${props.sessionKey}`;

onMounted(() => {
    echo()
        .channel(channelName)
        .listen('.state', (p: State) => {
            Object.assign(state, p);
            distribution.value = null;
        })
        .listen('.timer', (p: any) => (state.deadline = p.deadline))
        .listen('.reveal', () => {})
        .listen('.board_distribution', (p: any) => (distribution.value = p))
        .listen('.board_podium', (p: any) => (podium.value = p.entries ?? []))
        .listen('.ended', () => (state.status = 'ended'));
});
onBeforeUnmount(() => echo().leave(channelName));

const promptText = computed(() =>
    state.question ? tr(state.question.prompt_text, locale.value) : '',
);
</script>

<template>
    <Head :title="gameTitle" />

    <div class="flex min-h-screen flex-col bg-gradient-to-br from-indigo-600 to-purple-700 text-white">
        <header class="flex items-center justify-between p-6">
            <h1 class="text-2xl font-bold">{{ gameTitle }}</h1>
            <div v-if="remaining !== null" class="font-mono text-4xl font-black">
                {{ state.mode === 'escape' ? formatClock(remaining) : remaining + 's' }}
            </div>
        </header>

        <main class="flex flex-1 flex-col items-center justify-center p-8">
            <!-- Podium (Ende) -->
            <div v-if="state.status === 'ended' && podium" class="w-full max-w-3xl">
                <h2 class="mb-8 text-center text-5xl font-black">🏆</h2>
                <div class="rounded-3xl bg-white p-8 text-gray-800 shadow-xl">
                    <Podium :entries="podium" />
                </div>
            </div>

            <!-- Escape-Room läuft -->
            <div v-else-if="state.mode === 'escape' && state.status !== 'lobby' && state.status !== 'ended'" class="text-center">
                <div class="mb-6 text-9xl">🔐</div>
                <p class="text-4xl font-bold">{{ t('escape.running') }}</p>
                <p v-if="remaining !== null" class="mt-6 font-mono text-8xl font-black">{{ formatClock(remaining) }}</p>
                <p v-if="state.status === 'paused'" class="mt-6 text-3xl">⏸ {{ t('escape.paused') }}</p>
            </div>

            <!-- Lobby -->
            <div v-else-if="!state.question || state.status === 'lobby'" class="text-center">
                <p class="mb-6 text-3xl">{{ t('play.joinTitle') }}</p>
                <div v-if="joinMethod === 'pin'">
                    <p class="text-2xl">{{ t('control.pin') }}</p>
                    <p class="text-8xl font-black tracking-widest">{{ pin }}</p>
                </div>
                <div v-else class="rounded-2xl bg-white p-4">
                    <QrCode :value="joinUrl" :size="280" />
                </div>
                <p class="mt-4 text-lg opacity-80">{{ joinUrl }}</p>
            </div>

            <!-- Frage / Auflösung -->
            <div v-else class="w-full max-w-4xl text-center">
                <p class="text-lg opacity-70">
                    {{ (state.question_index ?? 0) + 1 }} / {{ state.question_total }}
                </p>
                <h2 class="mt-2 text-4xl font-bold">{{ promptText }}</h2>
                <img
                    v-if="state.question.prompt_image_url"
                    :src="state.question.prompt_image_url"
                    class="mx-auto mt-6 max-h-72 rounded-xl object-contain"
                    alt=""
                />

                <!-- Auflösung: anonyme Verteilung -->
                <div v-if="distribution" class="mt-8 rounded-2xl bg-white/95 p-6 text-gray-800">
                    <ResultDistribution :dist="distribution" />
                </div>

                <!-- sonst: Antwortoptionen (choice) groß anzeigen -->
                <div
                    v-else-if="state.question.options"
                    class="mt-8 grid gap-4 sm:grid-cols-2"
                >
                    <div
                        v-for="o in state.question.options"
                        :key="o.id"
                        class="rounded-xl bg-white/90 p-5 text-xl font-medium text-gray-800"
                    >
                        {{ tr(o.text, locale) }}
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
