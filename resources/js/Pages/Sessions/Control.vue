<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/ui/Card.vue';
import QrCode from '@/Components/QrCode.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Podium from '@/Components/Play/Podium.vue';
import ManualGrading from '@/Components/Play/ManualGrading.vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useTeacherSession } from '@/Composables/useTeacherSession';
import { useCountdown } from '@/Composables/useCountdown';
import { useResultExport } from '@/Composables/useResultExport';
import { distribution as buildDistribution } from '@/lib/aggregate';
import { tr, type Question } from '@/types/quiz';
import { formatClock } from '@/types/escape';

const props = defineProps<{
    session: Record<string, any>;
    game: Record<string, any>;
    state: Record<string, any>;
    questions: Question[];
}>();

const { t } = useI18n();

const {
    state,
    list,
    leaderboard,
    escapeRanking,
    openGradings,
    answeredCount,
    setManualPoints,
    clear,
    persistFailed,
    applyState,
} = useTeacherSession(props.session.id, props.session.session_key, props.questions, props.state);

// Nach jeder eigenen Aktion liefert der Server den neuen Stand mit – auch wenn die
// WebSocket-Verbindung gerade hängt (sonst: doppelt "Weiter" geklickt → Frage übersprungen).
watch(
    () => props.state,
    (s) => s && applyState(s),
);

const { remaining } = useCountdown(() => state.deadline ?? null);

const isEscape = computed(() => props.session.mode === 'escape');

const currentQuestion = computed(() =>
    state.question ? props.questions.find((q) => q.id === state.question.id) : null,
);
const answered = computed(() =>
    state.question ? answeredCount(state.question.id) : 0,
);
const isTwoPhasePrompt = computed(() => state.phase === 'prompt');
const currentNeedsGrading = computed(
    () => !!currentQuestion.value && ['draw', 'free_text'].includes(currentQuestion.value.type) && answered.value > 0,
);
const hasGradableQuestions = computed(() =>
    props.questions.some((q) => ['draw', 'free_text'].includes(q.type) && q.scored),
);

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
const startEscape = () => post(route('sessions.escape.start', s.id));
const addEscapeTime = () => post(route('sessions.timer', s.id), { mode: 'extend', seconds: 300 });

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

// ---- Escape-Room ----
const roomTotal = computed(() => state.escape_room_count ?? 0);

function escapeStatus(p: any): string {
    const e = p.escape;
    if (e?.escapedMs != null) return `🎉 ${formatClock(e.escapedMs / 1000)}`;
    return `${t('escape.room')} ${(e?.room ?? 0) + 1}${roomTotal.value ? ' / ' + roomTotal.value : ''}`;
}

const podiumEntries = computed(() =>
    isEscape.value
        ? escapeRanking.value.map((p) => ({
              label: p.label,
              score: p.escape?.solved.length ?? 0,
              display: escapeStatus(p),
          }))
        : leaderboard.value.map((p) => ({ label: p.label, score: p.score })),
);

function pushPodium() {
    axios.post(route('sessions.board', s.id), {
        event: 'podium',
        payload: { entries: podiumEntries.value },
    });
}

function exportCsv() {
    exportResults(props.game.title, props.questions, leaderboard.value);
}

function endGame() {
    if (openGradings.value > 0 && !confirm(t('grading.confirmEnd', { count: openGradings.value }))) {
        return;
    }
    // Bei offenen Bewertungen das Podium erst nach dem Bewerten an die Tafel schicken.
    if (openGradings.value === 0) pushPodium();
    post(route('sessions.end', s.id));
}

const ended = computed(() => state.status === 'ended');

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

const clock = computed(() =>
    remaining.value === null ? null : isEscape.value ? formatClock(remaining.value) : `${remaining.value}s`,
);
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

        <!-- Ende: Podium + (ggf. offene Bewertungen) + Export -->
        <div v-if="ended" class="mx-auto max-w-5xl space-y-6 px-4 py-10">
            <Card v-if="!isEscape && hasGradableQuestions">
                <template #header>
                    {{ t('grading.title') }}
                    <span v-if="openGradings > 0" class="ml-2 normal-case text-amber-600">
                        ({{ openGradings }} {{ t('grading.open') }})
                    </span>
                </template>
                <template #actions>
                    <SecondaryButton @click="pushPodium">🖥️ {{ t('grading.pushPodium') }}</SecondaryButton>
                </template>
                <ManualGrading
                    :questions="questions"
                    :participants="list"
                    :locale="game.base_locale"
                    @grade="setManualPoints"
                />
            </Card>

            <Card>
                <div class="py-6">
                    <h2 class="mb-8 text-center text-3xl font-black text-gray-900 dark:text-gray-100">
                        🏆 {{ t('control.leaderboard') }}
                    </h2>
                    <div class="rounded-2xl bg-white p-6 shadow-inner">
                        <Podium :entries="podiumEntries" />
                    </div>
                    <div class="mt-10 flex justify-center gap-3">
                        <PrimaryButton v-if="!isEscape" @click="exportCsv">⬇ {{ t('control.exportResults') }}</PrimaryButton>
                        <SecondaryButton @click="finishAndLeave">{{ $t('common.back') }}</SecondaryButton>
                    </div>
                </div>
            </Card>
        </div>

        <div v-if="persistFailed" class="mx-auto mt-4 max-w-7xl px-4 text-sm text-amber-700 dark:text-amber-300">
            ⚠️ {{ t('control.storageFull') }}
        </div>
        <div v-if="!ended" class="mx-auto grid max-w-7xl gap-6 px-4 py-8 sm:px-6 lg:grid-cols-3 lg:px-8">
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
                    <!-- Escape-Room-Steuerung -->
                    <div v-if="isEscape" class="grid grid-cols-2 gap-2">
                        <PrimaryButton
                            v-if="state.status === 'lobby'"
                            class="col-span-2 justify-center"
                            @click="startEscape"
                        >
                            🔐 {{ t('escape.start') }}
                        </PrimaryButton>
                        <template v-else>
                            <SecondaryButton @click="addEscapeTime">⏱ {{ t('escape.addTime') }}</SecondaryButton>
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

                    <!-- Quiz-Steuerung -->
                    <div v-else class="grid grid-cols-2 gap-2">
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
                        <p v-if="openGradings > 0" class="col-span-2 text-center text-xs text-amber-600">
                            {{ openGradings }} {{ t('grading.openHint') }}
                        </p>
                    </div>
                    <div
                        v-if="clock !== null"
                        class="mt-3 text-center text-2xl font-bold"
                        :class="remaining !== null && remaining <= (isEscape ? 60 : 5) ? 'text-red-600' : 'text-gray-900 dark:text-gray-100'"
                    >
                        {{ clock }}
                    </div>
                </Card>
            </div>

            <!-- Escape: Fortschritt aller Teams -->
            <div v-if="isEscape" class="space-y-6 lg:col-span-2">
                <Card>
                    <template #header>
                        {{ t('escape.progress') }} ({{ list.filter((p) => p.connected).length }} {{ t('control.connected') }})
                    </template>
                    <div v-if="list.length === 0" class="text-sm text-gray-400">{{ t('control.noPlayers') }}</div>
                    <table v-else class="w-full text-sm text-gray-800 dark:text-gray-200">
                        <thead>
                            <tr class="text-left text-xs uppercase text-gray-500">
                                <th class="py-1">#</th>
                                <th class="py-1">{{ t('control.players') }}</th>
                                <th class="py-1">{{ t('escape.status') }}</th>
                                <th class="py-1 text-right">🧩</th>
                                <th class="py-1 text-right">✗</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(p, i) in escapeRanking"
                                :key="p.token"
                                class="border-t border-gray-100 dark:border-gray-700"
                                :class="p.focus === 'away' && p.connected ? 'bg-red-50 dark:bg-red-900/20' : ''"
                            >
                                <td class="py-1.5 text-gray-400">{{ i + 1 }}</td>
                                <td class="py-1.5">
                                    <span class="mr-2 inline-block h-2.5 w-2.5 rounded-full" :class="focusClass(p)" />
                                    {{ p.label }}
                                </td>
                                <td class="py-1.5" :class="p.escape?.escapedMs != null ? 'font-semibold text-green-600' : ''">
                                    {{ escapeStatus(p) }}
                                </td>
                                <td class="py-1.5 text-right">{{ p.escape?.solved.length ?? 0 }}</td>
                                <td class="py-1.5 text-right text-gray-500">{{ p.escape?.wrong ?? 0 }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mt-3 text-xs text-gray-400">{{ t('escape.progressHint') }}</p>
                </Card>
            </div>

            <!-- Quiz: Live-Grid + aktuelle Frage -->
            <div v-else class="space-y-6 lg:col-span-2">
                <Card v-if="currentQuestion">
                    <template #header>{{ t('control.question') }} {{ (state.question_index ?? 0) + 1 }} / {{ state.question_total }}</template>
                    <template #actions>
                        <span class="text-sm text-gray-500">{{ answered }} {{ t('control.answered') }}</span>
                    </template>
                    <p class="text-lg font-medium text-gray-900 dark:text-gray-100">
                        {{ currentQuestion.prompt_text?.[game.base_locale] || currentQuestion.prompt_text?.de }}
                    </p>
                </Card>

                <!-- Bewertung (Malen immer, eigene Antwort optional überstimmen) -->
                <Card v-if="currentNeedsGrading" :title="t('grading.title')">
                    <ManualGrading
                        :questions="questions"
                        :participants="list"
                        :question-id="currentQuestion!.id"
                        :locale="game.base_locale"
                        @grade="setManualPoints"
                    />
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
                            class="flex items-center gap-2 rounded-lg border p-2 text-sm text-gray-800 dark:border-gray-700 dark:text-gray-200"
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
