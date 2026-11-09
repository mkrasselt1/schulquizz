<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import ImageUpload from '@/Components/ui/ImageUpload.vue';
import { QUESTION_TYPE_META, type Question, type Translations } from '@/types/quiz';
import { HOTSPOT_ICON, type EscapeConfig, type EscapeHotspot, type HotspotKind } from '@/types/escape';

/**
 * Raum-Editor: Hintergrundbild hochladen, Hotspots per Ziehen aufs Bild zeichnen,
 * verschieben/skalieren und konfigurieren (Hinweis, Rätsel, Schloss).
 */
const props = defineProps<{
    gameId: number;
    baseLocale: string;
    mediaBase: string;
    escape: EscapeConfig;
    questions: Question[];
}>();

const emit = defineEmits<{ dirty: [value: boolean] }>();
const { t } = useI18n();
const L = props.baseLocale;

const data = ref<EscapeConfig>(clone(props.escape));
const dirty = ref(false);
const saving = ref(false);
const savedAt = ref(0);
const errors = ref<string[]>([]);
let loading = true;

function clone<T>(v: T): T {
    return JSON.parse(JSON.stringify(v));
}
watch(
    data,
    () => {
        if (!loading) dirty.value = true;
    },
    { deep: true },
);
loading = false;
watch(dirty, (d) => emit('dirty', d));

const roomIndex = ref(0);
const room = computed(() => data.value.rooms[roomIndex.value]);
const selectedId = ref<string | null>(null);
const selected = computed(() => room.value?.hotspots.find((h) => h.id === selectedId.value) ?? null);

const inputClass =
    'block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200';
const labelClass = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300';

// ---- Übersetzbare Felder (bearbeitet wird die Basissprache) ----
function trGet(v: Translations | undefined): string {
    return v?.[L] ?? '';
}
function trSet(obj: any, key: string, value: string) {
    obj[key] = { ...(obj[key] ?? {}), [L]: value };
}

function rid(prefix: string) {
    return prefix + Math.random().toString(36).slice(2, 10);
}

// ---- Räume ----
function addRoom() {
    data.value.rooms.push({
        id: rid('r_'),
        title: { [L]: `${t('escape.room')} ${data.value.rooms.length + 1}` },
        image_path: null,
        hotspots: [],
    });
    roomIndex.value = data.value.rooms.length - 1;
    selectedId.value = null;
}
function removeRoom() {
    if (data.value.rooms.length <= 1 || !confirm(t('escape.confirmDeleteRoom'))) return;
    data.value.rooms.splice(roomIndex.value, 1);
    roomIndex.value = Math.max(0, roomIndex.value - 1);
    selectedId.value = null;
}
function moveRoom(dir: -1 | 1) {
    const i = roomIndex.value;
    const j = i + dir;
    if (j < 0 || j >= data.value.rooms.length) return;
    const rooms = data.value.rooms;
    [rooms[i], rooms[j]] = [rooms[j], rooms[i]];
    roomIndex.value = j;
}

// ---- Hotspots zeichnen / verschieben / skalieren ----
const canvas = ref<HTMLElement | null>(null);
const draft = ref<{ x: number; y: number; w: number; h: number } | null>(null);
let drag: null | {
    mode: 'draw' | 'move' | 'resize';
    startX: number;
    startY: number;
    orig?: { x: number; y: number; w: number; h: number };
    hotspot?: EscapeHotspot;
} = null;

function pct(e: PointerEvent): { x: number; y: number } {
    const r = canvas.value!.getBoundingClientRect();
    return {
        x: Math.min(100, Math.max(0, ((e.clientX - r.left) / r.width) * 100)),
        y: Math.min(100, Math.max(0, ((e.clientY - r.top) / r.height) * 100)),
    };
}
const round = (n: number) => Math.round(n * 10) / 10;

function onCanvasDown(e: PointerEvent) {
    const p = pct(e);
    drag = { mode: 'draw', startX: p.x, startY: p.y };
    draft.value = { x: p.x, y: p.y, w: 0, h: 0 };
    selectedId.value = null;
    bindDrag();
}
function onHotspotDown(e: PointerEvent, h: EscapeHotspot, mode: 'move' | 'resize') {
    e.stopPropagation();
    selectedId.value = h.id;
    const p = pct(e);
    drag = { mode, startX: p.x, startY: p.y, orig: { x: h.x, y: h.y, w: h.w, h: h.h }, hotspot: h };
    bindDrag();
}
function onMove(e: PointerEvent) {
    if (!drag) return;
    const p = pct(e);
    if (drag.mode === 'draw') {
        draft.value = {
            x: Math.min(drag.startX, p.x),
            y: Math.min(drag.startY, p.y),
            w: Math.abs(p.x - drag.startX),
            h: Math.abs(p.y - drag.startY),
        };
    } else if (drag.hotspot && drag.orig) {
        const dx = p.x - drag.startX;
        const dy = p.y - drag.startY;
        const h = drag.hotspot;
        if (drag.mode === 'move') {
            h.x = round(Math.min(100 - h.w, Math.max(0, drag.orig.x + dx)));
            h.y = round(Math.min(100 - h.h, Math.max(0, drag.orig.y + dy)));
        } else {
            h.w = round(Math.min(100 - h.x, Math.max(2, drag.orig.w + dx)));
            h.h = round(Math.min(100 - h.y, Math.max(2, drag.orig.h + dy)));
        }
    }
}
function onUp() {
    if (drag?.mode === 'draw' && draft.value && draft.value.w > 2 && draft.value.h > 2) {
        const h: EscapeHotspot = {
            id: rid('h_'),
            kind: 'clue',
            x: round(draft.value.x),
            y: round(draft.value.y),
            w: round(draft.value.w),
            h: round(draft.value.h),
            label: { [L]: '' },
            text: { [L]: '' },
            image_path: null,
            hint: { [L]: '' },
            question_id: null,
            reward: { [L]: '' },
            code: '',
            requires: [],
        };
        room.value.hotspots.push(h);
        selectedId.value = h.id;
    }
    drag = null;
    draft.value = null;
    unbindDrag();
}
function bindDrag() {
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', onUp);
}
function unbindDrag() {
    window.removeEventListener('pointermove', onMove);
    window.removeEventListener('pointerup', onUp);
}
onBeforeUnmount(unbindDrag);

function removeHotspot() {
    if (!selected.value) return;
    const id = selected.value.id;
    room.value.hotspots = room.value.hotspots.filter((h) => h.id !== id);
    for (const r of data.value.rooms) for (const h of r.hotspots) h.requires = h.requires.filter((x) => x !== id);
    selectedId.value = null;
}

function setKind(kind: HotspotKind) {
    const h = selected.value;
    if (!h) return;
    h.kind = kind;
    if (kind === 'clue') {
        // Ein Hinweis wird nie "gelöst" – wer darauf wartet, bliebe für immer unsichtbar.
        for (const r of data.value.rooms) for (const o of r.hotspots) o.requires = o.requires.filter((x) => x !== h.id);
        h.requires = [];
    }
}

function toggleRequire(id: string) {
    const h = selected.value;
    if (!h) return;
    h.requires = h.requires.includes(id) ? h.requires.filter((x) => x !== id) : [...h.requires, id];
}

// Hotspots, von denen der ausgewählte abhängen kann (lösbare im selben Raum)
const requireCandidates = computed(() =>
    (room.value?.hotspots ?? []).filter((h) => h.id !== selectedId.value && h.kind !== 'clue'),
);

function hotspotName(h: EscapeHotspot): string {
    return trGet(h.label) || t(`escape.kind.${h.kind}`);
}
function questionName(q: Question, i: number): string {
    const text = (q.prompt_text?.[L] ?? '').replace(/\[\[|\]\]/g, '');
    return `${i + 1}. ${QUESTION_TYPE_META[q.type].icon} ${text || t(`editor.type.${q.type}`)}`;
}

const isLastRoom = computed(() => roomIndex.value === data.value.rooms.length - 1);

// ---- Plausibilitätsprüfung ----
const warnings = computed(() => {
    const out: string[] = [];
    const qids = new Set(props.questions.map((q) => q.id));
    data.value.rooms.forEach((r, i) => {
        const name = trGet(r.title) || `${t('escape.room')} ${i + 1}`;
        if (!r.hotspots.some((h) => h.kind === 'lock')) out.push(t('escape.warnNoLock', { room: name }));
        const kinds = new Map(r.hotspots.map((h) => [h.id, h.kind]));
        for (const h of r.hotspots) {
            if (h.requires.some((id) => kinds.get(id) === 'clue' || !kinds.has(id)))
                out.push(t('escape.warnRequiresClue', { room: name, hotspot: hotspotName(h) }));
            if (h.kind === 'puzzle' && (!h.question_id || !qids.has(h.question_id)))
                out.push(t('escape.warnNoPuzzle', { room: name, hotspot: hotspotName(h) }));
            if (h.kind === 'lock' && !h.code.trim())
                out.push(t('escape.warnNoCode', { room: name, hotspot: hotspotName(h) }));
        }
    });
    return out;
});

let pendingSave = false;
function save() {
    // Läuft schon ein Speichervorgang, danach erneut speichern (statt ihn abzubrechen).
    if (saving.value) {
        pendingSave = true;
        return;
    }
    saving.value = true;
    const sent = JSON.stringify(data.value);
    router.put(
        route('escape.update', props.gameId),
        { escape: JSON.parse(sent) },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                errors.value = [];
                savedAt.value = Date.now();
                const fresh = (page.props as any).game?.escape;
                // Nur übernehmen, wenn seit dem Absenden nichts geändert wurde – sonst
                // gingen Eingaben verloren, die während des Speicherns gemacht wurden.
                if (fresh && JSON.stringify(data.value) === sent) {
                    loading = true;
                    data.value = clone(fresh);
                    queueMicrotask(() => (loading = false));
                    dirty.value = false;
                }
            },
            onError: (errs) => {
                errors.value = Object.values(errs);
            },
            onFinish: () => {
                saving.value = false;
                if (pendingSave) {
                    pendingSave = false;
                    save();
                }
            },
        },
    );
}

const imageUrl = computed(() => (room.value?.image_path ? props.mediaBase + room.value.image_path : ''));
const imageBroken = ref(false);
watch(imageUrl, () => (imageBroken.value = false));

// Neues Hintergrundbild direkt speichern – sonst ist es nach einem Neuladen weg.
watch(
    () => data.value.rooms.map((r) => r.image_path).join('|'),
    (now, before) => {
        if (!loading && now !== before) save();
    },
);

// Vor dem Verlassen mit ungespeicherten Änderungen warnen.
function beforeUnload(e: BeforeUnloadEvent) {
    if (dirty.value) {
        e.preventDefault();
        e.returnValue = '';
    }
}
window.addEventListener('beforeunload', beforeUnload);
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));
</script>

<template>
    <div class="space-y-6 text-gray-800 dark:text-gray-200">
        <!-- Allgemein -->
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label :class="labelClass">📜 {{ t('escape.intro') }}</label>
                <textarea
                    :value="trGet(data.intro)"
                    rows="3"
                    :class="inputClass"
                    :placeholder="t('escape.introPlaceholder')"
                    @input="trSet(data, 'intro', ($event.target as HTMLTextAreaElement).value)"
                />
            </div>
            <div>
                <label :class="labelClass">🎉 {{ t('escape.outro') }}</label>
                <textarea
                    :value="trGet(data.outro)"
                    rows="3"
                    :class="inputClass"
                    :placeholder="t('escape.outroPlaceholder')"
                    @input="trSet(data, 'outro', ($event.target as HTMLTextAreaElement).value)"
                />
            </div>
            <div>
                <label :class="labelClass">⏱ {{ t('escape.timeLimit') }}</label>
                <input
                    type="number"
                    min="1"
                    max="600"
                    :value="data.time_limit ?? ''"
                    :class="inputClass"
                    @input="data.time_limit = ($event.target as HTMLInputElement).value ? Number(($event.target as HTMLInputElement).value) : null"
                />
            </div>
            <label class="flex items-center gap-2 self-end text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" v-model="data.show_hotspots" />
                {{ t('escape.showHotspots') }}
            </label>
        </div>

        <!-- Räume -->
        <div>
            <div class="mb-3 flex flex-wrap items-center gap-2">
                <button
                    v-for="(r, i) in data.rooms"
                    :key="r.id"
                    type="button"
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="
                        i === roomIndex
                            ? 'bg-indigo-600 text-white'
                            : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600'
                    "
                    @click="(roomIndex = i), (selectedId = null)"
                >
                    🚪 {{ trGet(r.title) || `${t('escape.room')} ${i + 1}` }}
                </button>
                <SecondaryButton type="button" @click="addRoom">+ {{ t('escape.addRoom') }}</SecondaryButton>
            </div>

            <div v-if="room" class="grid gap-4 md:grid-cols-3">
                <div class="md:col-span-2">
                    <input
                        :value="trGet(room.title)"
                        :class="inputClass"
                        class="mb-2"
                        :placeholder="t('escape.roomTitle')"
                        @input="trSet(room, 'title', ($event.target as HTMLInputElement).value)"
                    />

                    <!-- Zeichenfläche -->
                    <div
                        ref="canvas"
                        class="relative w-full cursor-crosshair touch-none select-none overflow-hidden rounded-lg bg-gradient-to-br from-slate-600 to-slate-800"
                        :class="imageUrl && !imageBroken ? '' : 'aspect-video'"
                        @pointerdown="onCanvasDown"
                    >
                        <img
                            v-if="imageUrl && !imageBroken"
                            :src="imageUrl"
                            alt=""
                            class="pointer-events-none block w-full"
                            draggable="false"
                            @error="imageBroken = true"
                        />
                        <div
                            v-if="imageBroken"
                            class="pointer-events-none absolute inset-x-0 top-0 bg-red-600/90 p-2 text-center text-xs text-white"
                        >
                            {{ t('escape.imageBroken') }} ({{ imageUrl }})
                        </div>
                        <div
                            v-if="!imageUrl && room.hotspots.length === 0"
                            class="pointer-events-none absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-white/70"
                        >
                            {{ t('escape.canvasEmpty') }}
                        </div>
                        <div
                            v-for="h in room.hotspots"
                            :key="h.id"
                            class="absolute cursor-move rounded border-2"
                            :class="
                                h.id === selectedId
                                    ? 'border-yellow-300 bg-yellow-300/30'
                                    : 'border-dashed border-white bg-white/15 hover:bg-white/25'
                            "
                            :style="{ left: h.x + '%', top: h.y + '%', width: h.w + '%', height: h.h + '%' }"
                            @pointerdown="onHotspotDown($event, h, 'move')"
                        >
                            <span class="pointer-events-none absolute left-1 top-1 rounded bg-black/60 px-1 text-xs text-white">
                                {{ HOTSPOT_ICON[h.kind] }} {{ hotspotName(h) }}
                                <span v-if="h.requires.length">· 🔗</span>
                            </span>
                            <span
                                class="absolute -bottom-1.5 -right-1.5 h-3.5 w-3.5 cursor-se-resize rounded-sm border border-gray-800 bg-yellow-300"
                                @pointerdown="onHotspotDown($event, h, 'resize')"
                            />
                        </div>
                        <div
                            v-if="draft"
                            class="pointer-events-none absolute border-2 border-yellow-300 bg-yellow-300/20"
                            :style="{ left: draft.x + '%', top: draft.y + '%', width: draft.w + '%', height: draft.h + '%' }"
                        />
                    </div>
                    <p class="mt-1 text-xs text-gray-500">{{ t('escape.canvasHint') }}</p>

                    <div class="mt-3 flex flex-wrap items-end gap-4">
                        <div>
                            <label :class="labelClass">🖼️ {{ t('escape.background') }}</label>
                            <ImageUpload :game-id="gameId" :media-base="mediaBase" v-model="room.image_path" />
                        </div>
                        <div class="ml-auto flex gap-2">
                            <SecondaryButton type="button" :disabled="roomIndex === 0" @click="moveRoom(-1)">◀</SecondaryButton>
                            <SecondaryButton type="button" :disabled="isLastRoom" @click="moveRoom(1)">▶</SecondaryButton>
                            <DangerButton type="button" :disabled="data.rooms.length <= 1" @click="removeRoom">
                                {{ t('escape.deleteRoom') }}
                            </DangerButton>
                        </div>
                    </div>
                </div>

                <!-- Eigenschaften des Hotspots -->
                <div class="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <p v-if="!selected" class="text-sm text-gray-500">{{ t('escape.selectHotspot') }}</p>
                    <div v-else class="space-y-3">
                        <div class="grid grid-cols-3 gap-1">
                            <button
                                v-for="k in (['clue', 'puzzle', 'lock'] as HotspotKind[])"
                                :key="k"
                                type="button"
                                class="rounded-md px-2 py-1.5 text-xs"
                                :class="
                                    selected.kind === k
                                        ? 'bg-indigo-600 text-white'
                                        : 'bg-gray-100 text-gray-700 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200'
                                "
                                @click="setKind(k)"
                            >
                                {{ HOTSPOT_ICON[k] }} {{ t(`escape.kind.${k}`) }}
                            </button>
                        </div>
                        <p class="text-xs text-gray-500">{{ t(`escape.kindHelp.${selected.kind}`) }}</p>

                        <div>
                            <label :class="labelClass">{{ t('escape.label') }}</label>
                            <input
                                :value="trGet(selected.label)"
                                :class="inputClass"
                                :placeholder="t('escape.labelPlaceholder')"
                                @input="trSet(selected, 'label', ($event.target as HTMLInputElement).value)"
                            />
                        </div>

                        <!-- Hinweis -->
                        <template v-if="selected.kind === 'clue'">
                            <div>
                                <label :class="labelClass">{{ t('escape.clueText') }}</label>
                                <textarea
                                    :value="trGet(selected.text)"
                                    rows="3"
                                    :class="inputClass"
                                    @input="trSet(selected, 'text', ($event.target as HTMLTextAreaElement).value)"
                                />
                            </div>
                            <div>
                                <label :class="labelClass">{{ t('escape.clueImage') }}</label>
                                <ImageUpload :game-id="gameId" :media-base="mediaBase" v-model="selected.image_path" />
                            </div>
                        </template>

                        <!-- Rätsel -->
                        <template v-if="selected.kind === 'puzzle'">
                            <div>
                                <label :class="labelClass">{{ t('escape.puzzle') }}</label>
                                <select v-model="selected.question_id" :class="inputClass">
                                    <option :value="null">—</option>
                                    <option v-for="(q, i) in questions" :key="q.id" :value="q.id">
                                        {{ questionName(q, i) }}
                                    </option>
                                </select>
                                <p v-if="questions.length === 0" class="mt-1 text-xs text-amber-600">{{ t('escape.noPuzzles') }}</p>
                            </div>
                            <div>
                                <label :class="labelClass">🎁 {{ t('escape.reward') }}</label>
                                <input
                                    :value="trGet(selected.reward)"
                                    :class="inputClass"
                                    :placeholder="t('escape.rewardPlaceholder')"
                                    @input="trSet(selected, 'reward', ($event.target as HTMLInputElement).value)"
                                />
                            </div>
                        </template>

                        <!-- Schloss -->
                        <template v-if="selected.kind === 'lock'">
                            <div>
                                <label :class="labelClass">{{ t('escape.lockText') }}</label>
                                <input
                                    :value="trGet(selected.text)"
                                    :class="inputClass"
                                    :placeholder="t('escape.lockTextPlaceholder')"
                                    @input="trSet(selected, 'text', ($event.target as HTMLInputElement).value)"
                                />
                            </div>
                            <div>
                                <label :class="labelClass">🔑 {{ t('escape.code') }}</label>
                                <input v-model="selected.code" :class="inputClass" class="font-mono" maxlength="100" />
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ isLastRoom ? t('escape.lockExit') : t('escape.lockNext') }}
                                </p>
                            </div>
                        </template>

                        <div v-if="selected.kind !== 'clue'">
                            <label :class="labelClass">💡 {{ t('escape.hint') }}</label>
                            <input
                                :value="trGet(selected.hint)"
                                :class="inputClass"
                                @input="trSet(selected, 'hint', ($event.target as HTMLInputElement).value)"
                            />
                        </div>

                        <div v-if="requireCandidates.length">
                            <label :class="labelClass">🔗 {{ t('escape.requires') }}</label>
                            <label
                                v-for="c in requireCandidates"
                                :key="c.id"
                                class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-200"
                            >
                                <input type="checkbox" :checked="selected.requires.includes(c.id)" @change="toggleRequire(c.id)" />
                                {{ HOTSPOT_ICON[c.kind] }} {{ hotspotName(c) }}
                            </label>
                        </div>

                        <DangerButton type="button" class="w-full justify-center" @click="removeHotspot">
                            {{ t('escape.deleteHotspot') }}
                        </DangerButton>
                    </div>
                </div>
            </div>
        </div>

        <!-- Warnungen + Speichern -->
        <ul v-if="warnings.length" class="space-y-1 rounded-md bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
            <li v-for="(w, i) in warnings" :key="i">⚠️ {{ w }}</li>
        </ul>
        <ul v-if="errors.length" class="space-y-1 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-200">
            <li class="font-semibold">{{ t('editor.saveFailed') }}</li>
            <li v-for="(e, i) in errors" :key="i">• {{ e }}</li>
        </ul>
        <div class="flex items-center gap-3">
            <PrimaryButton type="button" :disabled="saving" @click="save">{{ t('common.save') }}</PrimaryButton>
            <span v-if="dirty" class="text-sm text-amber-600">{{ t('escape.unsaved') }}</span>
            <span v-else-if="savedAt" class="text-sm text-green-600">✓ {{ t('editor.saved') }}</span>
        </div>
    </div>
</template>
