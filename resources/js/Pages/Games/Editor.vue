<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import Card from '@/Components/ui/Card.vue';
import Modal from '@/Components/Modal.vue';
import QrCode from '@/Components/QrCode.vue';
import QuestionForm from '@/Components/QuestionEditor/QuestionForm.vue';
import EscapeEditor from '@/Components/Escape/EscapeEditor.vue';
import type { EscapeConfig } from '@/types/escape';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { QUESTION_TYPE_META, type Question, type QuestionType } from '@/types/quiz';

interface GameProp {
    id: number;
    title: string;
    description: string | null;
    base_locale: string;
    status: string;
    settings: Record<string, unknown> | null;
    mode: 'quiz' | 'escape';
    escape: EscapeConfig | null;
    join_token: string;
    join_url: string;
}

const props = defineProps<{
    game: GameProp;
    questions: Question[];
    questionTypes: QuestionType[];
    mediaBase: string;
}>();

const { t } = useI18n();
const isEscape = computed(() => props.game.mode === 'escape');

// ---- Ungespeicherte Änderungen nicht stillschweigend verwerfen ----
const questionDirty = ref(false);
const escapeDirty = ref(false);
const anyDirty = computed(() => questionDirty.value || escapeDirty.value);

function confirmDiscard(): boolean {
    return !questionDirty.value || confirm(t('editor.unsavedConfirm'));
}
function selectQuestion(id: number) {
    if (id === selectedId.value || !confirmDiscard()) return;
    selectedId.value = id;
}
// Wegnavigieren innerhalb der App (z. B. "Zurück") abfangen.
const removeGuard = router.on('before', (event) => {
    if (event.detail.visit.method === 'get' && anyDirty.value && !confirm(t('editor.unsavedConfirm'))) {
        return false;
    }
});
onBeforeUnmount(() => removeGuard());

// Wo wird die ausgewählte Frage im Escape-Room benutzt? (Warnung beim Löschen)
const selectedUsedBy = computed(() => {
    const id = selectedId.value;
    const out: string[] = [];
    props.game.escape?.rooms.forEach((r, i) => {
        for (const h of r.hotspots) {
            if (h.kind === 'puzzle' && h.question_id === id) {
                out.push(`${r.title?.[props.game.base_locale] || i + 1} / ${h.label?.[props.game.base_locale] || '🧩'}`);
            }
        }
    });
    return out;
});

const selectedId = ref<number | null>(props.questions[0]?.id ?? null);
const selected = computed(() => props.questions.find((q) => q.id === selectedId.value) ?? null);

const showTypeMenu = ref(false);

watch(
    () => props.questions.map((q) => q.id).join(','),
    () => {
        if (!props.questions.some((q) => q.id === selectedId.value)) {
            selectedId.value = props.questions[0]?.id ?? null;
            questionDirty.value = false;
        }
    },
);

const metaForm = useForm({
    title: props.game.title,
    description: props.game.description ?? '',
    status: props.game.status,
});

function saveMeta() {
    metaForm.put(route('games.update', props.game.id), { preserveScroll: true });
}

const adding = ref(false);
function addQuestion(type: QuestionType) {
    showTypeMenu.value = false;
    if (adding.value || !confirmDiscard()) return;
    adding.value = true;
    router.post(
        route('questions.store', props.game.id),
        { type },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                // Neue Frage auswählen (letzte in der Liste nach Reload).
                const list = (page.props.questions as Question[]) ?? [];
                selectedId.value = list[list.length - 1]?.id ?? selectedId.value;
            },
            onFinish: () => (adding.value = false),
        },
    );
}

// Drag & Drop Sortierung
const dragIndex = ref<number | null>(null);
function onDragStart(i: number) {
    dragIndex.value = i;
}
function onDrop(i: number) {
    if (dragIndex.value === null || dragIndex.value === i) return;
    const ids = props.questions.map((q) => q.id);
    const [moved] = ids.splice(dragIndex.value, 1);
    ids.splice(i, 0, moved);
    dragIndex.value = null;
    router.post(route('questions.reorder', props.game.id), { ids }, { preserveScroll: true });
}

// Spiel starten
const showStart = ref(false);
const startForm = useForm({ join_method: 'qr', advance_mode: 'teacher', require_fullscreen: false });
function startGame() {
    startForm
        .transform((d) => ({
            join_method: d.join_method,
            advance_mode: d.advance_mode,
            settings: { require_fullscreen: d.require_fullscreen },
        }))
        .post(route('sessions.store', props.game.id));
}

function typeLabel(type: QuestionType): string {
    return t(`editor.type.${type}`);
}
function promptPreview(q: Question): string {
    return q.prompt_text?.[props.game.base_locale] || `(${typeLabel(q.type)})`;
}
</script>

<template>
    <Head :title="game.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    {{ game.title }}
                </h2>
                <div class="flex gap-2">
                    <PrimaryButton @click="showStart = true">▶ {{ $t('control.start') }}</PrimaryButton>
                    <Link :href="route('library')">
                        <SecondaryButton>{{ $t('common.back') }}</SecondaryButton>
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto grid max-w-7xl gap-6 px-4 sm:px-6 lg:grid-cols-4 lg:px-8">
                <!-- Linke Spalte: Fragenliste + QR -->
                <div class="space-y-6 lg:col-span-1">
                    <Card :title="isEscape ? t('escape.puzzles') : t('editor.questions')" :padded="false">
                        <template #actions>
                            <div class="relative">
                                <SecondaryButton type="button" @click="showTypeMenu = !showTypeMenu">
                                    +
                                </SecondaryButton>
                                <div
                                    v-if="showTypeMenu"
                                    class="absolute right-0 z-10 mt-1 w-52 rounded-md bg-white py-1 shadow-lg ring-1 ring-black/5 dark:bg-gray-700 dark:ring-white/10"
                                >
                                    <button
                                        v-for="type in questionTypes"
                                        :key="type"
                                        type="button"
                                        class="block w-full px-3 py-1.5 text-left text-sm text-gray-800 hover:bg-gray-100 dark:text-gray-100 dark:hover:bg-gray-600"
                                        @click="addQuestion(type)"
                                    >
                                        {{ QUESTION_TYPE_META[type].icon }} {{ typeLabel(type) }}
                                    </button>
                                </div>
                            </div>
                        </template>

                        <div v-if="questions.length === 0" class="p-5 text-sm text-gray-400">
                            {{ isEscape ? t('escape.noPuzzlesYet') : t('editor.noQuestions') }}
                        </div>
                        <ul v-else class="divide-y divide-gray-100 dark:divide-gray-700">
                            <li
                                v-for="(q, i) in questions"
                                :key="q.id"
                                draggable="true"
                                @dragstart="onDragStart(i)"
                                @dragover.prevent
                                @drop="onDrop(i)"
                                @click="selectQuestion(q.id)"
                                class="flex cursor-pointer items-center gap-2 px-4 py-2 text-sm text-gray-800 dark:text-gray-200"
                                :class="
                                    q.id === selectedId
                                        ? 'bg-indigo-50 dark:bg-indigo-900/40'
                                        : 'hover:bg-gray-50 dark:hover:bg-gray-700/40'
                                "
                            >
                                <span class="text-gray-300">⠿</span>
                                <span>{{ QUESTION_TYPE_META[q.type].icon }}</span>
                                <span class="flex-1 truncate">{{ i + 1 }}. {{ promptPreview(q) }}</span>
                            </li>
                        </ul>
                    </Card>

                    <Card :title="t('editor.qrTitle')">
                        <div class="flex flex-col items-center text-center">
                            <QrCode :value="game.join_url" :size="160" />
                            <p class="mt-2 break-all text-xs text-gray-400">{{ game.join_url }}</p>
                            <p class="mt-1 text-xs text-gray-400">{{ t('editor.qrHint') }}</p>
                        </div>
                    </Card>
                </div>

                <!-- Rechte Spalte: Metadaten + Fragen-Editor -->
                <div class="space-y-6 lg:col-span-3">
                    <Card :title="$t('library.edit')">
                        <form @submit.prevent="saveMeta" class="space-y-4">
                            <div>
                                <InputLabel for="title" :value="$t('library.titleLabel')" />
                                <TextInput id="title" v-model="metaForm.title" class="mt-1 block w-full" required />
                            </div>
                            <div>
                                <InputLabel for="description" :value="$t('library.descriptionLabel')" />
                                <textarea
                                    id="description"
                                    v-model="metaForm.description"
                                    rows="2"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                ></textarea>
                            </div>
                            <div class="flex items-center gap-3">
                                <select
                                    v-model="metaForm.status"
                                    class="rounded-md border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                                >
                                    <option value="draft">{{ $t('library.statusDraft') }}</option>
                                    <option value="ready">{{ $t('library.statusReady') }}</option>
                                </select>
                                <PrimaryButton :disabled="metaForm.processing">{{ $t('common.save') }}</PrimaryButton>
                                <span v-if="metaForm.recentlySuccessful" class="text-sm text-green-600">✓</span>
                            </div>
                            <div v-if="Object.keys(metaForm.errors).length" class="text-sm text-red-600">
                                <p v-for="(e, k) in metaForm.errors" :key="k">{{ e }}</p>
                            </div>
                        </form>
                    </Card>

                    <Card v-if="isEscape && game.escape" :title="'🔐 ' + t('escape.rooms')">
                        <EscapeEditor
                            @dirty="escapeDirty = $event"
                            :game-id="game.id"
                            :base-locale="game.base_locale"
                            :media-base="mediaBase"
                            :join-url="game.join_url"
                            :escape="game.escape"
                            :questions="questions"
                        />
                    </Card>

                    <Card v-if="selected" :title="(isEscape ? '🧩 ' : '') + typeLabel(selected.type)">
                        <QuestionForm
                            :key="selected.id"
                            :used-by="selectedUsedBy"
                            @dirty="questionDirty = $event"
                            :question="selected"
                            :game-id="game.id"
                            :base-locale="game.base_locale"
                            :media-base="mediaBase"
                        />
                    </Card>
                    <Card v-else>
                        <p class="text-sm text-gray-400">{{ t('editor.noQuestions') }}</p>
                    </Card>
                </div>
            </div>
        </div>

        <!-- Spiel starten -->
        <Modal :show="showStart" @close="showStart = false">
            <form @submit.prevent="startGame" class="space-y-5 p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ $t('control.startDialogTitle') }}
                </h2>

                <div>
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $t('control.joinMethod') }}
                    </span>
                    <div class="flex gap-2">
                        <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="qr" v-model="startForm.join_method" />
                            {{ $t('control.methodQr') }}
                        </label>
                        <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="pin" v-model="startForm.join_method" />
                            {{ $t('control.methodPin') }}
                        </label>
                    </div>
                </div>

                <div v-if="!isEscape">
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $t('control.advanceMode') }}
                    </span>
                    <div class="flex gap-2">
                        <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="teacher" v-model="startForm.advance_mode" />
                            {{ $t('control.advanceTeacher') }}
                        </label>
                        <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="free" v-model="startForm.advance_mode" />
                            {{ $t('control.advanceFree') }}
                        </label>
                    </div>
                </div>

                <div>
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" v-model="startForm.require_fullscreen" />
                        {{ $t('control.requireFullscreen') }}
                    </label>
                    <p class="mt-1 text-xs text-gray-400">{{ $t('control.fullscreenHint') }}</p>
                </div>

                <div class="flex justify-end gap-2">
                    <SecondaryButton type="button" @click="showStart = false">
                        {{ $t('common.cancel') }}
                    </SecondaryButton>
                    <PrimaryButton :disabled="startForm.processing">{{ $t('control.start') }}</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
