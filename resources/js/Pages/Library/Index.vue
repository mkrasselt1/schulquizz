<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import Modal from '@/Components/Modal.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';

interface GameItem {
    id: number;
    title: string;
    description: string | null;
    status: string;
    mode: 'quiz' | 'escape';
    always_open?: boolean;
    join_token: string;
    updated_at: string | null;
}

defineProps<{ games: GameItem[] }>();

const { t } = useI18n();

const showCreate = ref(false);
const createForm = useForm({ title: '', description: '', mode: 'quiz' as 'quiz' | 'escape' });
const importForm = useForm<{ file: File | null }>({ file: null });
const importInput = ref<HTMLInputElement | null>(null);

function submitCreate() {
    createForm.post(route('games.store'), {
        onSuccess: () => {
            showCreate.value = false;
            createForm.reset();
        },
    });
}

function triggerImport() {
    importInput.value?.click();
}

function onImportFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    if (!file) return;
    importForm.file = file;
    importForm.post(route('games.import'), {
        forceFormData: true,
        onFinish: () => {
            if (importInput.value) importInput.value.value = '';
        },
    });
}

const busy = ref(false);
function duplicate(game: GameItem) {
    if (busy.value) return; // Doppelklick → sonst zwei Kopien
    busy.value = true;
    router.post(route('games.duplicate', game.id), {}, { onFinish: () => (busy.value = false) });
}

// Spiel direkt aus der Bibliothek starten
const startGameItem = ref<GameItem | null>(null);
const startForm = useForm({ join_method: 'qr', advance_mode: 'teacher', require_fullscreen: false });
function openStart(game: GameItem) {
    startGameItem.value = game;
    startForm.reset();
}
function submitStart() {
    if (!startGameItem.value) return;
    startForm
        .transform((d) => ({
            join_method: d.join_method,
            advance_mode: d.advance_mode,
            settings: { require_fullscreen: d.require_fullscreen },
        }))
        .post(route('sessions.store', startGameItem.value.id));
}

function destroy(game: GameItem) {
    if (confirm(t('library.confirmDelete'))) {
        router.delete(route('games.destroy', game.id));
    }
}

function formatDate(iso: string | null): string {
    if (!iso) return '';
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head :title="t('library.title')" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    {{ t('library.title') }}
                </h2>
                <div class="flex gap-2">
                    <SecondaryButton @click="triggerImport" :disabled="importForm.processing">
                        {{ t('library.import') }}
                    </SecondaryButton>
                    <input
                        ref="importInput"
                        type="file"
                        accept=".json,application/json"
                        class="hidden"
                        @change="onImportFile"
                    />
                    <PrimaryButton @click="showCreate = true">
                        {{ t('library.newGame') }}
                    </PrimaryButton>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div v-if="importForm.errors.file" class="mb-4">
                    <InputError :message="importForm.errors.file" />
                </div>

                <div
                    v-if="games.length === 0"
                    class="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center text-gray-500 dark:border-gray-700 dark:bg-gray-800"
                >
                    {{ t('library.empty') }}
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div
                        v-for="game in games"
                        :key="game.id"
                        class="flex flex-col justify-between rounded-lg bg-white p-5 shadow-sm ring-1 ring-gray-100 dark:bg-gray-800 dark:ring-gray-700"
                    >
                        <div>
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                                    <span v-if="game.mode === 'escape'" :title="t('escape.modeEscape')">🔐</span>
                                    <span v-if="game.always_open" :title="t('escape.alwaysOpen')">🌐</span>
                                    {{ game.title }}
                                </h3>
                                <span
                                    class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="
                                        game.status === 'ready'
                                            ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
                                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'
                                    "
                                >
                                    {{ game.status === 'ready' ? t('library.statusReady') : t('library.statusDraft') }}
                                </span>
                            </div>
                            <p v-if="game.description" class="mt-1 line-clamp-2 text-sm text-gray-500 dark:text-gray-400">
                                {{ game.description }}
                            </p>
                            <p class="mt-2 text-xs text-gray-400">
                                {{ t('library.updatedAt') }}: {{ formatDate(game.updated_at) }}
                            </p>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <PrimaryButton @click="openStart(game)">▶ {{ t('library.startNow') }}</PrimaryButton>
                            <Link :href="route('games.edit', game.id)">
                                <SecondaryButton>{{ t('library.edit') }}</SecondaryButton>
                            </Link>
                            <Link v-if="game.mode === 'escape'" :href="route('escape.verify')">
                                <SecondaryButton>✅ {{ t('verify.short') }}</SecondaryButton>
                            </Link>
                            <SecondaryButton @click="duplicate(game)">
                                {{ t('library.duplicate') }}
                            </SecondaryButton>
                            <a :href="route('games.export', game.id)">
                                <SecondaryButton type="button">{{ t('library.export') }}</SecondaryButton>
                            </a>
                            <DangerButton @click="destroy(game)">
                                {{ t('library.delete') }}
                            </DangerButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Neues Quiz -->
        <Modal :show="showCreate" @close="showCreate = false">
            <form @submit.prevent="submitCreate" class="p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ t('library.newGameTitle') }}
                </h2>

                <div class="mt-4 grid grid-cols-2 gap-2">
                    <button
                        v-for="m in (['quiz', 'escape'] as const)"
                        :key="m"
                        type="button"
                        class="rounded-lg border-2 p-3 text-left text-sm transition"
                        :class="
                            createForm.mode === m
                                ? 'border-indigo-500 bg-indigo-50 text-gray-900 dark:bg-indigo-900/40 dark:text-gray-100'
                                : 'border-gray-200 text-gray-700 hover:border-gray-300 dark:border-gray-700 dark:text-gray-300'
                        "
                        @click="createForm.mode = m"
                    >
                        <div class="text-2xl">{{ m === 'quiz' ? '🎯' : '🔐' }}</div>
                        <div class="font-semibold">{{ m === 'quiz' ? t('escape.modeQuiz') : t('escape.modeEscape') }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            {{ m === 'quiz' ? t('escape.modeQuizHelp') : t('escape.modeEscapeHelp') }}
                        </div>
                    </button>
                </div>

                <div class="mt-4">
                    <InputLabel for="title" :value="t('library.titleLabel')" />
                    <TextInput id="title" v-model="createForm.title" class="mt-1 block w-full" required autofocus />
                    <InputError :message="createForm.errors.title" class="mt-1" />
                </div>

                <div class="mt-4">
                    <InputLabel for="description" :value="t('library.descriptionLabel')" />
                    <textarea
                        id="description"
                        v-model="createForm.description"
                        rows="3"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                    ></textarea>
                    <InputError :message="createForm.errors.description" class="mt-1" />
                </div>

                <div class="mt-6 flex justify-end gap-2">
                    <SecondaryButton @click="showCreate = false" type="button">
                        {{ $t('common.cancel') }}
                    </SecondaryButton>
                    <PrimaryButton :disabled="createForm.processing">
                        {{ $t('common.create') }}
                    </PrimaryButton>
                </div>
            </form>
        </Modal>

        <!-- Spiel direkt starten -->
        <Modal :show="startGameItem !== null" @close="startGameItem = null">
            <form v-if="startGameItem" @submit.prevent="submitStart" class="space-y-5 p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">
                    {{ $t('control.startDialogTitle') }}: {{ startGameItem.title }}
                </h2>

                <div>
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $t('control.joinMethod') }}
                    </span>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="qr" v-model="startForm.join_method" /> {{ $t('control.methodQr') }}
                        </label>
                        <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="pin" v-model="startForm.join_method" /> {{ $t('control.methodPin') }}
                        </label>
                    </div>
                </div>

                <div v-if="startGameItem.mode !== 'escape'">
                    <span class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ $t('control.advanceMode') }}
                    </span>
                    <div class="flex gap-3">
                        <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="teacher" v-model="startForm.advance_mode" /> {{ $t('control.advanceTeacher') }}
                        </label>
                        <label class="flex items-center gap-1 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" value="free" v-model="startForm.advance_mode" /> {{ $t('control.advanceFree') }}
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
                    <SecondaryButton type="button" @click="startGameItem = null">
                        {{ $t('common.cancel') }}
                    </SecondaryButton>
                    <PrimaryButton :disabled="startForm.processing">{{ $t('control.start') }}</PrimaryButton>
                </div>
            </form>
        </Modal>
    </AuthenticatedLayout>
</template>
