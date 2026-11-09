<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Question } from '@/types/quiz';
import { tr } from '@/types/quiz';
import type { LiveParticipant } from '@/Composables/useTeacherSession';

/**
 * Bewertung von Antworten, die der Computer nicht (sicher) prüfen kann:
 *  - Malen: immer manuell (bis zur Bewertung 0 Punkte, als "offen" markiert)
 *  - Eigene Antwort: automatisch geprüft, kann aber überstimmt werden (Tippfehler etc.)
 */
const props = defineProps<{
    questions: Question[];
    participants: LiveParticipant[];
    /** Nur diese Frage anzeigen; null = alle bewertbaren Fragen */
    questionId?: number | null;
    locale: string;
}>();

const emit = defineEmits<{ grade: [token: string, questionId: number, points: number] }>();
const { t } = useI18n();

const GRADABLE = ['draw', 'free_text'];

const groups = computed(() =>
    props.questions
        .filter((q) => GRADABLE.includes(q.type) && q.scored)
        .filter((q) => props.questionId == null || q.id === props.questionId)
        .map((q, i) => ({
            question: q,
            index: props.questions.indexOf(q),
            entries: props.participants
                .filter((p) => p.answers[q.id] !== undefined)
                .map((p) => ({ participant: p, answer: p.answers[q.id] })),
        }))
        .filter((g) => g.entries.length > 0),
);

function effective(a: { manualPoints?: number; points: number }): number {
    return a.manualPoints ?? a.points;
}

function isOpen(q: Question, a: { manualPoints?: number }): boolean {
    return q.type === 'draw' && a.manualPoints === undefined;
}
</script>

<template>
    <div class="space-y-6">
        <p v-if="groups.length === 0" class="text-sm text-gray-400">{{ t('grading.nothing') }}</p>

        <div v-for="g in groups" :key="g.question.id">
            <h4 v-if="questionId == null" class="mb-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                {{ t('control.question') }} {{ g.index + 1 }}: {{ tr(g.question.prompt_text, locale) }}
            </h4>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="e in g.entries"
                    :key="e.participant.token"
                    class="rounded-lg border p-2 dark:border-gray-700"
                    :class="isOpen(g.question, e.answer) ? 'border-amber-400 dark:border-amber-500' : ''"
                >
                    <div class="mb-2 flex items-center justify-between text-sm">
                        <span class="truncate font-medium text-gray-800 dark:text-gray-100">{{ e.participant.label }}</span>
                        <span
                            v-if="isOpen(g.question, e.answer)"
                            class="rounded bg-amber-100 px-1.5 text-xs text-amber-800 dark:bg-amber-900 dark:text-amber-200"
                        >
                            {{ t('grading.open') }}
                        </span>
                        <span v-else class="text-xs font-semibold text-gray-600 dark:text-gray-300">
                            {{ effective(e.answer) }} {{ t('grading.pts') }}
                        </span>
                    </div>

                    <img
                        v-if="g.question.type === 'draw'"
                        :src="e.answer.answer"
                        alt=""
                        class="w-full rounded border border-gray-200 bg-white dark:border-gray-600"
                    />
                    <p v-else class="break-words rounded bg-gray-50 p-2 text-gray-800 dark:bg-gray-900 dark:text-gray-100">
                        {{ e.answer.answer }}
                        <span class="ml-1 text-xs" :class="e.answer.correctness === 'correct' ? 'text-green-600' : 'text-red-500'">
                            ({{ e.answer.correctness === 'correct' ? t('grading.autoCorrect') : t('grading.autoWrong') }})
                        </span>
                    </p>

                    <div class="mt-2 grid grid-cols-3 gap-1">
                        <button
                            type="button"
                            class="rounded bg-green-600 py-1 text-sm text-white hover:bg-green-700"
                            :title="t('grading.full')"
                            @click="emit('grade', e.participant.token, g.question.id, g.question.points)"
                        >
                            ✓
                        </button>
                        <button
                            type="button"
                            class="rounded bg-amber-500 py-1 text-sm text-white hover:bg-amber-600"
                            :title="t('grading.half')"
                            @click="emit('grade', e.participant.token, g.question.id, Math.round(g.question.points / 2))"
                        >
                            ½
                        </button>
                        <button
                            type="button"
                            class="rounded bg-red-600 py-1 text-sm text-white hover:bg-red-700"
                            :title="t('grading.none')"
                            @click="emit('grade', e.participant.token, g.question.id, 0)"
                        >
                            ✗
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
