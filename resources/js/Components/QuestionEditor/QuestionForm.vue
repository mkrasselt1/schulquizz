<script setup lang="ts">
import { reactive, ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import type { Question, AnswerOption } from '@/types/quiz';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import FormField from '@/Components/ui/FormField.vue';
import ImageUpload from '@/Components/ui/ImageUpload.vue';
import { parseCloze } from '@/lib/cloze';

const props = defineProps<{
    question: Question;
    gameId: number;
    baseLocale: string;
    mediaBase: string;
    /** Escape-Room-Hotspots, die diese Frage als Rätsel nutzen (Warnung beim Löschen) */
    usedBy?: string[];
}>();

const emit = defineEmits<{ dirty: [value: boolean] }>();

const { t } = useI18n();

const inputClass =
    'block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';

// ---- Lokaler, editierbarer Zustand (Basis-Locale) ----
interface EditOption {
    id?: number;
    _text: string;
    _origText: Record<string, string>;
    mode: 'text' | 'image';
    image_path: string | null;
    is_correct: boolean;
}

const state = reactive({
    prompt: '',
    origPrompt: {} as Record<string, string>,
    prompt_mode: 'text' as 'text' | 'image',
    prompt_image_path: null as string | null,
    time_limit: null as number | null,
    points: 1000,
    scored: true,
    reveal_after: null as boolean | null,
    config: {} as Record<string, any>,
    options: [] as EditOption[],
    saving: false,
    savedAt: 0,
    errors: [] as string[],
});

// ---- Ungespeicherte Änderungen erkennen ----
function editableJson(): string {
    return JSON.stringify({
        prompt: state.prompt,
        prompt_mode: state.prompt_mode,
        prompt_image_path: state.prompt_image_path,
        time_limit: state.time_limit,
        points: state.points,
        scored: state.scored,
        reveal_after: state.reveal_after,
        config: state.config,
        options: state.options,
    });
}
const baseline = ref('');
const dirty = computed(() => editableJson() !== baseline.value);
watch(dirty, (d) => emit('dirty', d), { immediate: true });

function loadFrom(q: Question) {
    state.origPrompt = { ...(q.prompt_text ?? {}) };
    state.prompt = state.origPrompt[props.baseLocale] ?? '';
    state.prompt_mode = q.prompt_mode;
    state.prompt_image_path = q.prompt_image_path;
    state.time_limit = q.time_limit;
    state.points = q.points;
    state.scored = q.scored;
    state.reveal_after = q.reveal_after;
    state.config = JSON.parse(JSON.stringify(q.config ?? {}));
    // Slider-Pole vollständig anlegen (auch bei importierten/Bild-Polen ohne Text),
    // damit das Formular nicht beim Rendern abstürzt.
    if (q.type === 'poll_slider') {
        for (const side of ['left', 'right']) {
            const pole = state.config[side] ?? {};
            state.config[side] = { mode: 'text', ...pole, text: { ...(pole.text ?? {}) } };
        }
    }
    state.options = (q.options ?? []).map((o: AnswerOption) => ({
        id: o.id,
        _text: (o.text ?? {})[props.baseLocale] ?? '',
        _origText: { ...(o.text ?? {}) },
        mode: o.mode,
        image_path: o.image_path,
        is_correct: o.is_correct,
    }));
    state.errors = [];
    baseline.value = editableJson();
}

watch(() => props.question.id, () => loadFrom(props.question), { immediate: true });

const hasOptions = computed(() => ['choice', 'sort'].includes(props.question.type));
const isUnscored = computed(() => ['poll_slider', 'word_cloud'].includes(props.question.type));

function addOption() {
    state.options.push({ _text: '', _origText: {}, mode: 'text', image_path: null, is_correct: false });
}
function removeOption(i: number) {
    state.options.splice(i, 1);
}

// free_text: kommagetrennte akzeptierte Antworten <-> array
const acceptedCsv = computed({
    get: () => (state.config.accepted ?? []).join(', '),
    set: (v: string) => {
        state.config.accepted = v.split(',').map((s) => s.trim()).filter(Boolean);
    },
});

// cloze: Lücken live aus dem Fragetext [[…]] parsen (nur Vorschau).
const clozeGaps = computed(() => parseCloze(state.prompt).gaps);

function sliderPole(side: 'left' | 'right') {
    return state.config[side];
}

function save() {
    state.saving = true;

    const payload: Record<string, any> = {
        prompt_mode: state.prompt_mode,
        prompt_text: { ...state.origPrompt, [props.baseLocale]: state.prompt },
        prompt_image_path: state.prompt_image_path,
        // Zahlenfelder normalisieren (leere Felder / Werte außerhalb der Grenzen).
        time_limit: state.time_limit ? Math.min(3600, Math.max(3, Math.round(Number(state.time_limit)))) : null,
        points: isUnscored.value ? 0 : Math.min(100000, Math.max(0, Math.round(Number(state.points) || 0))),
        scored: isUnscored.value ? false : state.scored,
        reveal_after: state.reveal_after,
        config: state.config,
        options: hasOptions.value
            ? state.options.map((o, i) => ({
                  id: o.id,
                  mode: o.mode,
                  text: { ...o._origText, [props.baseLocale]: o._text },
                  image_path: o.image_path,
                  is_correct: o.is_correct,
                  position: i,
              }))
            : [],
    };

    const sent = editableJson();
    router.put(route('questions.update', [props.gameId, props.question.id]), payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: (page) => {
            state.savedAt = Date.now();
            state.errors = [];
            // Frisch vom Server übernehmen (neue Antwort-IDs!) – aber nur, wenn seit dem
            // Absenden nichts mehr geändert wurde.
            const fresh = ((page.props as any).questions as Question[] | undefined)?.find(
                (q) => q.id === props.question.id,
            );
            if (fresh && editableJson() === sent) loadFrom(fresh);
            else baseline.value = sent;
        },
        onError: (errors) => {
            state.errors = Object.values(errors);
        },
        onFinish: () => (state.saving = false),
    });
}

function destroy() {
    const msg = props.usedBy?.length
        ? t('editor.confirmDeleteUsedQuestion', { where: props.usedBy.join(', ') })
        : t('editor.confirmDeleteQuestion');
    if (confirm(msg)) {
        router.delete(route('questions.destroy', [props.gameId, props.question.id]), {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <div class="space-y-5">
        <!-- Prompt -->
        <FormField :label="t('editor.promptMode')">
            <div class="flex gap-2">
                <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                    <input type="radio" value="text" v-model="state.prompt_mode" /> {{ t('editor.asText') }}
                </label>
                <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                    <input type="radio" value="image" v-model="state.prompt_mode" /> {{ t('editor.asImage') }}
                </label>
            </div>
        </FormField>

        <FormField :label="t('editor.prompt')">
            <textarea v-model="state.prompt" rows="2" :class="inputClass"></textarea>
        </FormField>

        <FormField v-if="state.prompt_mode === 'image'" :label="t('editor.promptImage')">
            <ImageUpload
                :game-id="gameId"
                :media-base="mediaBase"
                v-model="state.prompt_image_path"
            />
        </FormField>

        <!-- Typ-spezifisch -->
        <!-- CHOICE / SORT: Antwortliste -->
        <div v-if="hasOptions" class="space-y-2">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('editor.answers') }}</span>
                <SecondaryButton type="button" @click="addOption">+ {{ t('editor.addAnswer') }}</SecondaryButton>
            </div>
            <p v-if="question.type === 'sort'" class="text-xs text-gray-400">{{ t('editor.sortHint') }}</p>

            <div
                v-for="(opt, i) in state.options"
                :key="i"
                class="flex items-center gap-2 rounded-md border border-gray-200 p-2 dark:border-gray-700"
            >
                <span class="w-5 text-center text-xs text-gray-400">{{ i + 1 }}</span>
                <input v-model="opt._text" :class="inputClass" class="flex-1" :placeholder="t('editor.answers')" />
                <label v-if="question.type === 'choice'" class="flex items-center gap-1 whitespace-nowrap text-xs text-gray-700 dark:text-gray-200">
                    <input type="checkbox" v-model="opt.is_correct" /> {{ t('editor.correct') }}
                </label>
                <button type="button" class="text-red-500" @click="removeOption(i)">✕</button>
            </div>

            <label v-if="question.type === 'choice'" class="mt-2 flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" v-model="state.config.multiple" /> {{ t('editor.multiple') }}
            </label>
            <label v-if="question.type === 'choice'" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" v-model="state.config.randomize" /> {{ t('editor.randomize') }}
            </label>
        </div>

        <!-- CLOZE -->
        <div v-else-if="question.type === 'cloze'" class="space-y-2">
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ t('editor.clozeHint') }}</p>
            <div
                v-if="clozeGaps.length"
                class="rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-900"
            >
                <div v-for="(g, i) in clozeGaps" :key="i" class="text-gray-700 dark:text-gray-300">
                    {{ t('editor.gap') }} {{ i + 1 }}:
                    <span class="font-medium">{{ g.accepted.join(' / ') || '—' }}</span>
                </div>
            </div>
            <p v-else class="text-xs text-amber-600">{{ t('editor.clozeNoGap') }}</p>
        </div>

        <!-- FREE TEXT -->
        <div v-else-if="question.type === 'free_text'" class="space-y-3">
            <FormField :label="t('editor.acceptedAnswers')">
                <input v-model="acceptedCsv" :class="inputClass" />
            </FormField>
            <FormField :label="t('editor.threshold')">
                <input type="number" step="0.05" min="0" max="1" v-model.number="state.config.threshold" :class="inputClass" />
            </FormField>
        </div>

        <!-- ESTIMATE -->
        <div v-else-if="question.type === 'estimate'" class="grid grid-cols-3 gap-3">
            <FormField :label="t('editor.estimateCorrect')">
                <input type="number" v-model.number="state.config.correct" :class="inputClass" />
            </FormField>
            <FormField :label="t('editor.estimateTolerance')">
                <input type="number" min="0" v-model.number="state.config.tolerance" :class="inputClass" />
            </FormField>
            <FormField :label="t('editor.estimateUnit')">
                <input v-model="state.config.unit" :class="inputClass" />
            </FormField>
        </div>

        <!-- POLL SLIDER -->
        <div v-else-if="question.type === 'poll_slider'" class="space-y-3">
            <FormField :label="t('editor.sliderLeft')">
                <input v-model="sliderPole('left').text[baseLocale]" :class="inputClass" />
            </FormField>
            <FormField :label="t('editor.sliderRight')">
                <input v-model="sliderPole('right').text[baseLocale]" :class="inputClass" />
            </FormField>
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" v-model="state.config.live_results" /> {{ t('editor.liveResults') }}
            </label>
        </div>

        <!-- WORD CLOUD -->
        <div v-else-if="question.type === 'word_cloud'">
            <FormField :label="t('editor.maxWords')">
                <input type="number" min="1" max="10" v-model.number="state.config.max_words" :class="inputClass" />
            </FormField>
        </div>

        <!-- DRAW -->
        <div v-else-if="question.type === 'draw'" class="rounded-md bg-gray-50 p-3 text-sm text-gray-500 dark:bg-gray-900">
            {{ t('editor.drawInfo') }}
        </div>

        <!-- Gemeinsame Parameter -->
        <div class="grid grid-cols-2 gap-3 border-t border-gray-100 pt-4 dark:border-gray-700">
            <FormField :label="t('editor.timeLimit')">
                <input type="number" min="3" v-model.number="state.time_limit" :class="inputClass" />
            </FormField>
            <FormField v-if="!isUnscored" :label="t('editor.points')">
                <input type="number" min="0" v-model.number="state.points" :class="inputClass" />
            </FormField>
            <label v-if="!isUnscored" class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" v-model="state.scored" /> {{ t('editor.scored') }}
            </label>
            <FormField :label="t('editor.revealAfter')">
                <select v-model="state.reveal_after" :class="inputClass">
                    <option :value="null">{{ t('editor.revealInherit') }}</option>
                    <option :value="true">{{ t('common.yes') }}</option>
                    <option :value="false">{{ t('common.no') }}</option>
                </select>
            </FormField>
        </div>

        <ul v-if="state.errors.length" class="space-y-1 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-200">
            <li class="font-semibold">{{ t('editor.saveFailed') }}</li>
            <li v-for="(e, i) in state.errors" :key="i">• {{ e }}</li>
        </ul>

        <div class="flex items-center justify-between border-t border-gray-100 pt-4 dark:border-gray-700">
            <DangerButton type="button" @click="destroy">{{ t('editor.deleteQuestion') }}</DangerButton>
            <div class="flex items-center gap-3">
                <span v-if="dirty" class="text-sm text-amber-600">{{ t('escape.unsaved') }}</span>
                <span v-else-if="state.savedAt" class="text-sm text-green-600">✓ {{ t('editor.saved') }}</span>
                <PrimaryButton type="button" :disabled="state.saving" @click="save">
                    {{ $t('common.save') }}
                </PrimaryButton>
            </div>
        </div>
    </div>
</template>
