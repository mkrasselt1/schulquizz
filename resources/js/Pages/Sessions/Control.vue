<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/ui/Card.vue';
import QrCode from '@/Components/QrCode.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Podium from '@/Components/Play/Podium.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useTeacherSession } from '@/Composables/useTeacherSession';
import { useCountdown } from '@/Composables/useCountdown';
import { useResultExport } from '@/Composables/useResultExport';
import { distribution as buildDistribution } from '@/lib/aggregate';
import { tr, type Question } from '@/types/quiz';

const props = defineProps<{
    session: Record<string, any>;
    game: Record<string, any>;
    state: Record<string, any>;
    questions: Question[];
}>();

const { t } = useI18n();

const { state, list, leaderboard, answeredCount, clear } = useTeacherSession(
    props.session.session_key,
    props.questions,
    props.state,
);

const { remaining } = useCountdown(() => state.deadline ?? null);

const currentQuestion = computed(() =>
    state.question ? props.questions.find((q) => q.id === state.question.id) : null,
);
const answered = computed(() =>
    state.question ? answeredCount(state.question.id) : 0,
);
const isTwoPhasePrompt = computed(() => state.phase === 'prompt');

function post(url: string, data: Record<string, any> = {}) {
    router.post(url, data, { preserveScroll: true, preserveState: true });
}

const { exportResults } = useResultExport();

const s = props.session;
const begin = () => post(route('sessions.question', s.id), { direction: 'next' });
const next = () => post(route('sessions.question', s.id), { direction: 'next' });
const prev = () => post(route('sessions.question', s.id), { direction: 'prev' });
const showAnswers = () => post(route('sessions.phase', s.id));
const pause = () => post(route('sessions.pause', s.id));
const resume = () => post(route('sessions.resume', s.id));
const addTime = () => post(route('sessions.timer', s.id), { mode: 'extend', seconds: 10 });

// Auflösen: an Schüler broadcasten + anonyme Verteilung an die Tafel spiegeln.
function reveal() {
    post(route('sessions.reveal', s.id));
    if (currentQuestion.value) {
        const answers = list.value
            .map((p) => p.answers[currentQuestion.value!.id]?.answer)
            .filter((a) => a !== undefined);
        const dist = buildDistribution(currentQuestion.value, answers, (txt) =>
            tr(txt, props.game.base_locale),
        );
        axios.post(route('sessions.board', s.id), { event: 'distribution', payload: dist });
    }
}

function pushPodium() {
    axios.post(route('sessions.board', s.id), {
        event: 'podium',
        payload: { entries: leaderboard.value.map((p) => ({ label: p.label, score: p.score })) },
    });
}

function exportCsv() {
    exportResults(props.game.title, props.questions, leaderboard.value);
}

function endGame() {
    pushPodium(); // Siegertreppchen an die Tafel, bevor beendet wird
    post(route('sessions.end', s.id));
}

const ended = computed(() => state.status === 'ended');
const podiumEntries = computed(() =>
    leaderboard.value.map((p) => ({ label: p.label, score: p.score })),
);

// Aufräumen (localStorage leeren) und zurück zur Bibliothek – Datensparsamkeit.
function finishAndLeave() {
    clear();
    router.visit(route('library'));
}

function openBoard() {
    window.open(props.session.board_url, '_blank', 'noopener');
}

function focusClass(p: any): string {
    if (!p.connected) return 'bg-gray-300 dark:bg-gray-600';
    return p.focus === 'away' ? 'bg-red-500' : 'bg-green-500';
}
</script>

<template>
    <Head :title="t('control.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-200">
                    {{ game.title }} · {{ t('control.title') }}
                </h2>
                <SecondaryButton @click="openBoard">🖥️ {{ t('control.openBoard') }}</SecondaryButton>
            </div>
        </template>

        <!-- Ende: Podium + Export -->
        <div v-if="ended" class="mx-auto max-w-3xl px-4 py-10">
            <Card>
                <div class="py-6">
                    <h2 class="mb-8 text-center text-3xl font-black">🏆 {{ t('control.leaderboard') }}</h2>
                    <div class="rounded-2xl bg-white p-6 shadow-inner">
                        <Podium :entries="podiumEntries" />
                    </div>
                    <div class="mt-10 flex justify-center gap-3">
                        <PrimaryButton @click="exportCsv">⬇ {{ t('control.exportResults') }}</PrimaryButton>
                        <SecondaryButton @click="finishAndLeave">{{ $t('common.back') }}</SecondaryButton>
                    </div>
                </div>
            </Card>
        </div>

        <div v-else class="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-3 lg:px-8">
            <!-- Beitritt + Steuerung -->
            <div class="space-y-6 lg:col-span-1">
                <Card :title="t('control.joinInfo')">
                    <div class="text-center">
                        <template v-if="session.join_method === 'pin'">
                            <p class="text-sm text-gray-500">{{ t('control.pin') }}</p>
                            <p class="text-5xl font-black tracking-widest text-gray-900 dark:text-gray-100">
                                {{ session.pin }}
                            </p>
                            <p class="mt-2 text-xs text-gray-400">{{ session.join_url }}</p>
                        </template>
                        <template v-else>
                            <div class="flex justify-center"><QrCode :value="session.join_url" :size="180" /></div>
                            <p class="mt-2 break-all text-xs text-gray-400">{{ session.join_url }}</p>
                        </template>
                    </div>
                </Card>

                <Card :title="t('control.title')">
                    <div class="grid grid-cols-2 gap-2">
                        <PrimaryButton v-if="!state.question" class="col-span-2 justify-center" @click="begin">
                            {{ t('control.start') }}
                        </PrimaryButton>
                        <template v-else>
                            <SecondaryButton @click="prev">◀ {{ t('control.prev') }}</SecondaryButton>
                            <PrimaryButton class="justify-center" @click="next">{{ t('control.next') }} ▶</PrimaryButton>
                            <PrimaryButton v-if="isTwoPhasePrompt" class="col-span-2 justify-center" @click="showAnswers">
                                {{ t('control.showAnswers') }}
                            </PrimaryButton>
                            <SecondaryButton class="col-span-2 justify-center" @click="reveal">
                                💡 {{ t('control.reveal') }}
                            </SecondaryButton>
                            <SecondaryButton @click="addTime">⏱ {{ t('control.addTime') }}</SecondaryButton>
                            <SecondaryButton v-if="state.status === 'active'" @click="pause">
                                ⏸ {{ t('control.pause') }}
                            </SecondaryButton>
                            <SecondaryButton v-else-if="state.status === 'paused'" @click="resume">
                                ▶ {{ t('control.resume') }}
                            </SecondaryButton>
                        </template>
                        <DangerButton class="col-span-2 justify-center" @click="endGame">
                            {{ t('control.end') }}
                        </DangerButton>
                    </div>
                    <div v-if="remaining !== null" class="mt-3 text-center text-2xl font-bold" :class="remaining <= 5 ? 'text-red-600' : ''">
                        {{ remaining }}s
                    </div>
                </Card>
            </div>

            <!-- Live-Grid + aktuelle Frage -->
            <div class="space-y-6 lg:col-span-2">
                <Card v-if="currentQuestion">
                    <template #header>{{ t('control.question') }} {{ (state.question_index ?? 0) + 1 }} / {{ state.question_total }}</template>
                    <template #actions>
                        <span class="text-sm text-gray-500">{{ answered }} {{ t('control.answered') }}</span>
                    </template>
                    <p class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ currentQuestion.prompt_text?.[game.base_locale] || currentQuestion.prompt_text?.de }}
                    </p>
                </Card>

                <Card>
                    <template #header>
                        {{ t('control.players') }} ({{ list.filter((p) => p.connected).length }} {{ t('control.connected') }})
                    </template>
                    <div v-if="list.length === 0" class="text-sm text-gray-400">{{ t('control.noPlayers') }}</div>
                    <div v-else class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                        <div
                            v-for="p in leaderboard"
                            :key="p.token"
                            class="flex items-center gap-2 rounded-lg border p-2 text-sm dark:border-gray-700"
                            :class="p.focus === 'away' && p.connected ? 'border-red-300 bg-red-50 dark:bg-red-900/20' : ''"
                        >
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="focusClass(p)" />
                            <span class="flex-1 truncate">{{ p.label }}</span>
                            <span
                                v-if="state.question && p.answers[state.question.id]"
                                class="text-green-600"
                                title="geantwortet"
                            >✓</span>
                            <span class="font-semibold text-gray-700 dark:text-gray-300">{{ p.score }}</span>
                        </div>
                    </div>
                </Card>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
