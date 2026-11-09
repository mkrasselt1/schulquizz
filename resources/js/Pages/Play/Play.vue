<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { echo } from '@laravel/echo-vue';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import AnswerInput from '@/Components/Play/AnswerInput.vue';
import EscapePlay from '@/Components/Escape/EscapePlay.vue';
import { syncServerClock } from '@/lib/serverClock';
import { useProctoring } from '@/Composables/useProctoring';
import { useCountdown } from '@/Composables/useCountdown';
import { tr, type PublicQuestion } from '@/types/quiz';

interface State {
    mode?: 'quiz' | 'escape';
    escape?: any;
    started_at?: string | null;
    server_time?: string;
    status: string;
    advance_mode: string;
    phase: string | null;
    question_index: number | null;
    question_total: number;
    deadline: string | null;
    require_fullscreen?: boolean;
    question: PublicQuestion | null;
}

const props = defineProps<{
    participant: { token: string; label: string; kind: string };
    sessionKey: string;
    state: State;
}>();

const { t, locale } = useI18n();

const state = reactive<State>({ ...props.state });
const answer = ref<any>(undefined);
const submitted = ref(false);
const revealPayload = ref<any>(null);
let questionShownAt = 0;

const { away, fullscreen, requestFullscreen } = useProctoring();
const { remaining } = useCountdown(() => state.deadline);

// Reconnect-Daten sichern.
localStorage.setItem('schulquizz.participant', JSON.stringify(props.participant));

const channelName = `session.${props.sessionKey}`;

function resetForQuestion() {
    answer.value = undefined;
    submitted.value = false;
    revealPayload.value = null;
    questionShownAt = Date.now();
}

syncServerClock(props.state.server_time);

onMounted(() => {
    if (state.question) questionShownAt = Date.now();

    const channel = echo().channel(channelName);
    channel
        .listen('.state', (payload: State) => {
            syncServerClock(payload.server_time);
            const changedQuestion = payload.question?.id !== state.question?.id;
            const changedPhase = payload.phase !== state.phase;
            Object.assign(state, payload);
            if (changedQuestion || (changedPhase && payload.phase === 'answers')) {
                resetForQuestion();
            }
        })
        .listen('.timer', (p: { deadline: string; server_time?: string }) => {
            syncServerClock(p.server_time);
            state.deadline = p.deadline;
        })
        .listen('.reveal', (p: any) => {
            revealPayload.value = p.reveal;
        })
        .listen('.ended', () => {
            state.status = 'ended';
        });
});

onBeforeUnmount(() => {
    echo().leave(channelName);
});

const canAnswer = computed(
    () =>
        state.status === 'active' &&
        state.question &&
        state.phase !== 'prompt' &&
        !submitted.value &&
        (remaining.value === null || remaining.value > 0),
);

const promptText = computed(() =>
    state.question ? tr(state.question.prompt_text, locale.value) : '',
);

async function submit() {
    if (!state.question || answer.value === undefined) return;
    submitted.value = true;
    try {
        await axios.post(route('play.answer'), {
            question_id: state.question.id,
            answer: answer.value,
            response_ms: Date.now() - questionShownAt,
        });
    } catch {
        submitted.value = false;
    }
}
</script>

<template>
    <Head :title="t('play.joinTitle')" />

    <div class="flex min-h-screen flex-col bg-gradient-to-b from-indigo-50 to-white dark:from-gray-900 dark:to-gray-800">
        <!-- Kopfzeile: Teilnehmer + Timer + Fokuswarnung -->
        <header class="flex items-center justify-between p-4">
            <div class="text-2xl">{{ participant.label }}</div>
            <div
                v-if="remaining !== null && state.status === 'active' && state.mode !== 'escape'"
                class="rounded-full bg-white px-4 py-1 text-lg font-bold shadow dark:bg-gray-800"
                :class="remaining <= 5 ? 'text-red-600' : 'text-gray-800 dark:text-gray-100'"
            >
                {{ remaining }}s
            </div>
        </header>

        <div v-if="away" class="bg-amber-500 px-4 py-1 text-center text-sm text-white">
            ⚠️ Bitte zurück zum Spiel
        </div>

        <main class="flex flex-1 flex-col items-center justify-center p-6">
            <!-- Ende -->
            <div v-if="state.status === 'ended'" class="text-center">
                <div class="mb-4 text-6xl">🎉</div>
                <p class="text-xl text-gray-700 dark:text-gray-200">{{ t('play.ended') }}</p>
            </div>

            <!-- Escape-Room (selbstgesteuert) -->
            <EscapePlay
                v-else-if="state.mode === 'escape' && state.status !== 'lobby'"
                :session-key="sessionKey"
                :participant-token="participant.token"
                :status="state.status"
                :started-at="state.started_at ?? null"
                :remaining="remaining"
                :initial-escape="props.state.escape ?? null"
            />

            <!-- Lobby -->
            <div v-else-if="!state.question || state.status === 'lobby'" class="text-center">
                <div class="mb-4 text-6xl">✅</div>
                <h2 class="text-2xl font-bold text-gray-800 dark:text-gray-100">{{ t('play.lobbyTitle') }}</h2>
                <p class="mt-2 text-gray-500">{{ t('play.lobbyHint') }}</p>
                <button
                    v-if="state.require_fullscreen && !fullscreen"
                    class="mt-6 rounded-lg bg-indigo-600 px-5 py-2 text-white"
                    @click="requestFullscreen"
                >
                    {{ t('play.goFullscreen') }}
                </button>
            </div>

            <!-- Frage -->
            <div v-else class="w-full max-w-2xl">
                <div class="mb-6 text-center">
                    <p class="text-sm text-gray-400">
                        {{ (state.question_index ?? 0) + 1 }} / {{ state.question_total }}
                    </p>
                    <h2 v-if="promptText" class="mt-1 text-2xl font-bold text-gray-900 dark:text-gray-100">
                        {{ promptText }}
                    </h2>
                    <img
                        v-if="state.question.prompt_image_url"
                        :src="state.question.prompt_image_url"
                        class="mx-auto mt-4 max-h-64 rounded-lg object-contain"
                        alt=""
                    />
                </div>

                <!-- Phase "prompt": nur Frage, noch keine Antworten -->
                <div v-if="state.phase === 'prompt'" class="text-center text-gray-400">
                    …
                </div>

                <!-- Reveal -->
                <div
                    v-else-if="revealPayload"
                    class="rounded-xl bg-white p-6 text-center shadow dark:bg-gray-800"
                >
                    <p class="text-lg font-semibold text-gray-800 dark:text-gray-100">
                        {{ t('play.waitTeacher') }}
                    </p>
                </div>

                <!-- Antwort -->
                <template v-else>
                    <AnswerInput
                        :question="state.question"
                        :locale="locale"
                        :disabled="!canAnswer"
                        v-model:answer="answer"
                    />

                    <div class="mt-6 text-center">
                        <p v-if="submitted" class="text-lg font-medium text-green-600">
                            {{ t('play.answered') }}
                        </p>
                        <p v-else-if="remaining === 0" class="text-lg text-gray-500">
                            {{ t('play.timeUp') }}
                        </p>
                        <button
                            v-else
                            class="rounded-lg bg-indigo-600 px-6 py-3 text-lg text-white disabled:opacity-40"
                            :disabled="answer === undefined"
                            @click="submit"
                        >
                            {{ t('play.submit') }}
                        </button>
                    </div>
                </template>
            </div>
        </main>
    </div>
</template>
