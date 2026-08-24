<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import type { PublicQuestion } from '@/types/quiz';
import { tr } from '@/types/quiz';
import DrawCanvas from '@/Components/Play/DrawCanvas.vue';

const props = defineProps<{
    question: PublicQuestion;
    locale: string;
    disabled?: boolean;
}>();

// Antwortwert (Form je nach Typ). null = noch nichts gewählt.
const answer = defineModel<any>('answer');
const { t } = useI18n();

const inputClass =
    'block w-full rounded-lg border-gray-300 text-lg dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100';

function optText(o: any) {
    return tr(o.text, props.locale);
}

// --- CHOICE ---
const selected = ref<number[]>([]);
function toggleChoice(id: number) {
    if (props.disabled) return;
    if (props.question.config?.multiple) {
        selected.value = selected.value.includes(id)
            ? selected.value.filter((x) => x !== id)
            : [...selected.value, id];
    } else {
        selected.value = [id];
    }
    answer.value = selected.value;
}

// --- CLOZE ---
const clozeAnswers = ref<string[]>([]);
const clozeSegments = computed<any[]>(() => {
    const c = props.question.config?.cloze;
    if (!c) return [];
    return c[props.locale] ?? Object.values(c)[0] ?? [];
});
function setGap(i: number, val: string) {
    clozeAnswers.value[i] = val;
    answer.value = [...clozeAnswers.value];
}

// --- SORT (drag reorder) ---
const order = ref<any[]>([]);
watch(
    () => props.question.id,
    () => {
        order.value = [...(props.question.options ?? [])];
        selected.value = [];
        clozeAnswers.value = [];
        answer.value = props.question.type === 'sort' ? order.value.map((o) => o.id) : undefined;
    },
    { immediate: true },
);
const dragI = ref<number | null>(null);
function dropAt(i: number) {
    if (dragI.value === null || dragI.value === i) return;
    const arr = [...order.value];
    const [m] = arr.splice(dragI.value, 1);
    arr.splice(i, 0, m);
    order.value = arr;
    dragI.value = null;
    answer.value = arr.map((o) => o.id);
}

// --- WORD CLOUD ---
const words = ref<string[]>([]);
const wordInput = ref('');
const maxWords = computed(() => props.question.config?.max_words ?? 3);
function addWord() {
    const w = wordInput.value.trim();
    if (w && words.value.length < maxWords.value) {
        words.value.push(w);
        wordInput.value = '';
        answer.value = words.value;
    }
}

// --- SLIDER ---
const slider = ref(50);
function onSlider() {
    answer.value = slider.value;
}
</script>

<template>
    <div>
        <!-- CHOICE -->
        <div v-if="question.type === 'choice'" class="grid gap-3 sm:grid-cols-2">
            <button
                v-for="o in question.options"
                :key="o.id"
                type="button"
                :disabled="disabled"
                class="flex items-center gap-3 rounded-xl border-2 p-4 text-left text-lg transition"
                :class="selected.includes(o.id!)
                    ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/40'
                    : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'"
                @click="toggleChoice(o.id!)"
            >
                <img v-if="o.image_url" :src="o.image_url" class="h-16 w-16 rounded object-cover" alt="" />
                <span>{{ optText(o) }}</span>
            </button>
        </div>

        <!-- SORT -->
        <div v-else-if="question.type === 'sort'" class="space-y-2">
            <p class="text-sm text-gray-500">{{ t('play.sortHint') }}</p>
            <div
                v-for="(o, i) in order"
                :key="o.id"
                draggable="true"
                @dragstart="dragI = i"
                @dragover.prevent
                @drop="dropAt(i)"
                class="flex cursor-move items-center gap-2 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800"
            >
                <span class="text-gray-300">⠿</span>
                <span class="w-6 text-center font-bold text-indigo-500">{{ i + 1 }}</span>
                <img v-if="o.image_url" :src="o.image_url" class="h-10 w-10 rounded object-cover" alt="" />
                <span>{{ optText(o) }}</span>
            </div>
        </div>

        <!-- CLOZE: ein Feld pro Lücke, inline im Text -->
        <div v-else-if="question.type === 'cloze'" class="text-lg leading-loose text-gray-900 dark:text-gray-100">
            <template v-for="(seg, i) in clozeSegments" :key="i">
                <span v-if="seg.text !== undefined">{{ seg.text }}</span>
                <input
                    v-else
                    :value="clozeAnswers[seg.gap]"
                    @input="setGap(seg.gap, ($event.target as HTMLInputElement).value)"
                    :disabled="disabled"
                    class="mx-1 inline-block w-32 rounded border-0 border-b-2 border-indigo-400 bg-transparent px-1 text-center focus:outline-none focus:ring-0"
                />
            </template>
        </div>

        <!-- FREE TEXT -->
        <div v-else-if="question.type === 'free_text'">
            <input
                :value="answer"
                @input="answer = ($event.target as HTMLInputElement).value"
                :disabled="disabled"
                :class="inputClass"
                :placeholder="t('play.typeAnswer')"
            />
        </div>

        <!-- ESTIMATE -->
        <div v-else-if="question.type === 'estimate'" class="flex items-center gap-2">
            <input
                type="number"
                :value="answer"
                @input="answer = Number(($event.target as HTMLInputElement).value)"
                :disabled="disabled"
                :class="inputClass"
                :placeholder="t('play.typeAnswer')"
            />
            <span v-if="question.config?.unit" class="text-gray-500">{{ question.config.unit }}</span>
        </div>

        <!-- POLL SLIDER -->
        <div v-else-if="question.type === 'poll_slider'" class="space-y-3">
            <div class="flex justify-between text-sm font-medium text-gray-600 dark:text-gray-300">
                <span>{{ tr(question.config?.left?.text, locale) }}</span>
                <span>{{ tr(question.config?.right?.text, locale) }}</span>
            </div>
            <input
                type="range"
                min="0"
                max="100"
                v-model.number="slider"
                :disabled="disabled"
                class="w-full"
                @change="onSlider"
                @input="onSlider"
            />
        </div>

        <!-- WORD CLOUD -->
        <div v-else-if="question.type === 'word_cloud'" class="space-y-3">
            <div class="flex gap-2">
                <input
                    v-model="wordInput"
                    :disabled="disabled || words.length >= maxWords"
                    :class="inputClass"
                    :placeholder="t('play.typeAnswer')"
                    @keyup.enter="addWord"
                />
                <button
                    type="button"
                    class="rounded-lg bg-indigo-600 px-4 text-white disabled:opacity-40"
                    :disabled="disabled || words.length >= maxWords"
                    @click="addWord"
                >
                    +
                </button>
            </div>
            <div class="flex flex-wrap gap-2">
                <span
                    v-for="(w, i) in words"
                    :key="i"
                    class="rounded-full bg-indigo-100 px-3 py-1 text-sm text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200"
                >
                    {{ w }}
                </span>
            </div>
            <p class="text-xs text-gray-400">{{ words.length }} / {{ maxWords }} {{ t('play.words') }}</p>
        </div>

        <!-- DRAW -->
        <div v-else-if="question.type === 'draw'">
            <p class="mb-2 text-sm text-gray-500">{{ t('play.drawHint') }}</p>
            <DrawCanvas
                :palette="question.config?.palette"
                :brush-sizes="question.config?.brush_sizes"
                :default-brush="question.config?.default_brush"
                @change="answer = $event"
            />
        </div>
    </div>
</template>
