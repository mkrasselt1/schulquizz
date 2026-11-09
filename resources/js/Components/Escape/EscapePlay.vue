<script setup lang="ts">
import axios from 'axios';
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import AnswerInput from '@/Components/Play/AnswerInput.vue';
import { tr, type Translations } from '@/types/quiz';
import { formatClock, HOTSPOT_ICON, type PublicEscape, type PublicHotspot } from '@/types/escape';
import { serverNow } from '@/lib/serverClock';

/**
 * Escape-Room aus Schülersicht. Selbstgesteuert: Räume erkunden, Hinweise finden,
 * Rätsel lösen, Schlösser öffnen. Geprüft wird auf dem Server (Lösungen bleiben dort);
 * der eigene Fortschritt liegt nur lokal im Browser (übersteht ein Neuladen).
 */
const props = defineProps<{
    sessionKey: string;
    participantToken: string;
    status: string;
    startedAt: string | null;
    remaining: number | null;
    initialEscape: PublicEscape | null;
}>();

const { t, locale } = useI18n();

const escape = ref<PublicEscape | null>(props.initialEscape);
const loadError = ref(false);

let retryTimer: ReturnType<typeof setTimeout> | null = null;
async function load() {
    try {
        const res = await axios.get(route('play.escape'));
        escape.value = res.data;
        loadError.value = false;
    } catch {
        loadError.value = true;
        // Schul-WLAN: automatisch erneut versuchen
        if (retryTimer) clearTimeout(retryTimer);
        retryTimer = setTimeout(() => !escape.value && load(), 5000);
    }
}

watch(
    () => props.status,
    (s) => {
        if ((s === 'active' || s === 'paused') && !escape.value) load();
    },
    { immediate: true },
);

// ---- Lokaler Fortschritt ----
interface Progress {
    introSeen: boolean;
    room: number;
    solved: Record<string, Translations | null>; // Hotspot-ID → Belohnung
    escapedMs: number | null;
    receipts: any[]; // signierte Quittungen (für erneute Meldung an die Lehrkraft)
}

const storageKey = `schulquizz.escape.${props.sessionKey}.${props.participantToken}`;
const progress = reactive<Progress>({ introSeen: false, room: 0, solved: {}, escapedMs: null, receipts: [] });
try {
    Object.assign(progress, JSON.parse(localStorage.getItem(storageKey) || '{}'));
} catch {
    /* ignore */
}
watch(progress, () => {
    try {
        localStorage.setItem(storageKey, JSON.stringify(progress));
    } catch {
        /* ignore */
    }
});

const room = computed(() => escape.value?.rooms[progress.room] ?? null);
const introText = computed(() => (escape.value ? tr(escape.value.intro, locale.value) : ''));
const outroText = computed(() => (escape.value ? tr(escape.value.outro, locale.value) : ''));
const showIntro = computed(() => !!introText.value.trim() && !progress.introSeen);
const timeUp = computed(() => props.remaining === 0 && progress.escapedMs === null);

function isSolved(h: PublicHotspot): boolean {
    return h.id in progress.solved;
}
function isVisible(h: PublicHotspot): boolean {
    return h.requires.every((id) => id in progress.solved);
}
const visibleHotspots = computed(() => room.value?.hotspots.filter(isVisible) ?? []);

const inventory = computed(() =>
    Object.values(progress.solved)
        .filter((r): r is Translations => !!r && !!tr(r, locale.value).trim())
        .map((r) => tr(r, locale.value)),
);

// ---- Hotspot-Dialog ----
const active = ref<PublicHotspot | null>(null);
const answer = ref<any>(undefined);
const feedback = ref<'' | 'wrong' | 'correct' | 'paused' | 'time_up'>('');
const checking = ref(false);
const cooldown = ref(false);
const showHint = ref(false);
const toast = ref('');

function open(h: PublicHotspot) {
    if (props.status !== 'active' || timeUp.value) return;
    active.value = h;
    answer.value = undefined;
    feedback.value = '';
    showHint.value = false;
}
function close() {
    active.value = null;
}

async function check() {
    const h = active.value;
    if (!h || answer.value === undefined || answer.value === '' || checking.value || cooldown.value) return;
    checking.value = true;
    try {
        const res = await axios.post(route('play.escape.check'), { hotspot_id: h.id, answer: answer.value });
        if (!res.data.correct) {
            // Neu setzen, damit die Wackel-Animation auch beim zweiten Fehlversuch läuft.
            feedback.value = '';
            requestAnimationFrame(() => (feedback.value = 'wrong'));
            cooldown.value = true;
            setTimeout(() => (cooldown.value = false), 2000);
            return;
        }
        feedback.value = 'correct';
        progress.solved[h.id] = res.data.reward ?? null;
        if (res.data.receipt) progress.receipts.push(res.data.receipt);

        if (res.data.result === 'next_room') {
            progress.room++;
            close();
            flash(t('escape.doorOpens'));
        } else if (res.data.result === 'escaped') {
            progress.escapedMs = props.startedAt ? serverNow() - new Date(props.startedAt).getTime() : 0;
            close();
        }
    } catch (e: any) {
        const reason = e?.response?.data?.reason;
        feedback.value = reason === 'time_up' ? 'time_up' : 'paused';
        if (e?.response?.status === 429) {
            cooldown.value = true;
            setTimeout(() => (cooldown.value = false), 10000);
        }
    } finally {
        checking.value = false;
    }
}

function flash(msg: string) {
    toast.value = msg;
    setTimeout(() => (toast.value = ''), 2500);
}

function hotspotTitle(h: PublicHotspot): string {
    return tr(h.label, locale.value) || t(`escape.kind.${h.kind}`);
}
</script>

<template>
    <div class="w-full max-w-5xl">
        <!-- Kopf: Raum + Uhr -->
        <div class="mb-3 flex items-center justify-between gap-3 text-gray-800 dark:text-gray-100">
            <div class="text-lg font-bold">
                🔐
                <span v-if="room && progress.escapedMs === null">
                    {{ tr(room.title, locale) || `${t('escape.room')} ${progress.room + 1}` }}
                    <span class="text-sm font-normal text-gray-500">({{ progress.room + 1 }} / {{ escape?.rooms.length }})</span>
                </span>
            </div>
            <div
                v-if="remaining !== null && progress.escapedMs === null"
                class="rounded-full bg-white px-4 py-1 font-mono text-lg font-bold shadow dark:bg-gray-800"
                :class="remaining <= 60 ? 'text-red-600' : ''"
            >
                ⏱ {{ formatClock(remaining) }}
            </div>
        </div>

        <div v-if="!escape" class="py-20 text-center text-gray-500">
            <p>{{ loadError ? t('escape.loadError') : t('common.loading') }}</p>
            <button v-if="loadError" class="mt-4 rounded-lg bg-indigo-600 px-5 py-2 text-white" @click="load">
                {{ t('escape.retry') }}
            </button>
        </div>

        <!-- Entkommen -->
        <div v-else-if="progress.escapedMs !== null" class="rounded-2xl bg-white p-8 text-center shadow dark:bg-gray-800">
            <div class="mb-4 text-7xl">🎉</div>
            <h2 class="text-3xl font-black text-gray-900 dark:text-gray-100">{{ t('escape.escaped') }}</h2>
            <p class="mt-2 text-lg text-gray-600 dark:text-gray-300">
                {{ t('escape.yourTime') }}: <span class="font-mono font-bold">{{ formatClock(progress.escapedMs / 1000) }}</span>
            </p>
            <p v-if="outroText" class="mx-auto mt-6 max-w-xl whitespace-pre-line text-gray-700 dark:text-gray-200">{{ outroText }}</p>
        </div>

        <!-- Zeit abgelaufen -->
        <div v-else-if="timeUp" class="rounded-2xl bg-white p-8 text-center shadow dark:bg-gray-800">
            <div class="mb-4 text-7xl">⏰</div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ t('escape.timeUp') }}</h2>
            <p class="mt-2 text-gray-500">{{ t('escape.timeUpHint', { room: progress.room + 1 }) }}</p>
        </div>

        <!-- Intro / Geschichte -->
        <div v-else-if="showIntro" class="rounded-2xl bg-white p-8 shadow dark:bg-gray-800">
            <div class="mb-4 text-center text-6xl">📜</div>
            <p class="mx-auto max-w-2xl whitespace-pre-line text-lg text-gray-800 dark:text-gray-100">{{ introText }}</p>
            <div class="mt-8 text-center">
                <button class="rounded-lg bg-indigo-600 px-6 py-3 text-lg text-white" @click="progress.introSeen = true">
                    {{ t('escape.letsGo') }}
                </button>
            </div>
        </div>

        <!-- Raum -->
        <template v-else-if="room">
            <div
                class="relative w-full overflow-hidden rounded-xl bg-gradient-to-br from-slate-700 to-slate-900 shadow-lg"
                :class="room.image_url ? '' : 'aspect-video'"
            >
                <img v-if="room.image_url" :src="room.image_url" alt="" class="block w-full select-none" draggable="false" />
                <button
                    v-for="h in visibleHotspots"
                    :key="h.id"
                    type="button"
                    class="group absolute flex items-center justify-center rounded-lg transition"
                    :class="[
                        escape.show_hotspots
                            ? 'border-2 border-dashed border-white/80 bg-white/10 hover:bg-white/25'
                            : 'hover:bg-white/15',
                        isSolved(h) ? 'border-green-400 bg-green-400/20' : '',
                    ]"
                    :style="{ left: h.x + '%', top: h.y + '%', width: h.w + '%', height: h.h + '%' }"
                    :title="hotspotTitle(h)"
                    @click="open(h)"
                >
                    <span
                        v-if="escape.show_hotspots || isSolved(h)"
                        class="rounded-full bg-black/50 px-2 py-0.5 text-sm text-white"
                    >
                        {{ isSolved(h) ? '✓' : HOTSPOT_ICON[h.kind] }}
                        <span class="hidden sm:inline">{{ tr(h.label, locale) }}</span>
                    </span>
                </button>

                <div v-if="status === 'paused'" class="absolute inset-0 flex items-center justify-center bg-black/60 text-2xl font-bold text-white">
                    ⏸ {{ t('escape.paused') }}
                </div>
                <div
                    v-if="toast"
                    class="absolute left-1/2 top-4 -translate-x-1/2 rounded-full bg-green-600 px-4 py-2 font-semibold text-white shadow-lg"
                >
                    {{ toast }}
                </div>
            </div>
            <p class="mt-2 text-center text-sm text-gray-500">{{ t('escape.explore') }}</p>

            <!-- Inventar -->
            <div v-if="inventory.length" class="mt-4 rounded-xl bg-white p-4 shadow dark:bg-gray-800">
                <h3 class="mb-2 text-sm font-semibold uppercase text-gray-500">🎒 {{ t('escape.inventory') }}</h3>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="(item, i) in inventory"
                        :key="i"
                        class="rounded-lg bg-amber-100 px-3 py-1 text-amber-900 dark:bg-amber-900/50 dark:text-amber-100"
                    >
                        {{ item }}
                    </li>
                </ul>
            </div>
        </template>

        <!-- Dialog -->
        <div v-if="active" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="close">
            <div
                class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-800"
                :class="feedback === 'wrong' ? 'shake' : ''"
            >
                <div class="mb-4 flex items-start justify-between gap-3">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                        {{ HOTSPOT_ICON[active.kind] }} {{ hotspotTitle(active) }}
                    </h3>
                    <button type="button" class="text-2xl leading-none text-gray-400 hover:text-gray-600" @click="close">×</button>
                </div>

                <!-- Hinweis -->
                <template v-if="active.kind === 'clue'">
                    <p v-if="active.text" class="whitespace-pre-line text-lg text-gray-800 dark:text-gray-100">{{ tr(active.text, locale) }}</p>
                    <img v-if="active.image_url" :src="active.image_url" alt="" class="mx-auto mt-4 max-h-[60vh] rounded-lg" />
                </template>

                <!-- Bereits gelöst -->
                <template v-else-if="isSolved(active)">
                    <p class="text-lg font-semibold text-green-600">✓ {{ t('escape.solved') }}</p>
                    <p
                        v-if="progress.solved[active.id] && tr(progress.solved[active.id]!, locale)"
                        class="mt-3 rounded-lg bg-amber-100 p-4 text-lg text-amber-900 dark:bg-amber-900/50 dark:text-amber-100"
                    >
                        🎁 {{ tr(progress.solved[active.id]!, locale) }}
                    </p>
                </template>

                <!-- Rätsel -->
                <template v-else-if="active.kind === 'puzzle'">
                    <p v-if="!active.question" class="text-gray-500">{{ t('escape.puzzleMissing') }}</p>
                    <template v-else>
                        <p
                            v-if="tr(active.question.prompt_text, locale)"
                            class="mb-3 whitespace-pre-line text-lg font-medium text-gray-900 dark:text-gray-100"
                        >
                            {{ tr(active.question.prompt_text, locale) }}
                        </p>
                        <img
                            v-if="active.question.prompt_image_url"
                            :src="active.question.prompt_image_url"
                            alt=""
                            class="mx-auto mb-4 max-h-64 rounded-lg"
                        />
                        <AnswerInput
                            :key="active.id"
                            :question="active.question"
                            :locale="locale"
                            :disabled="checking"
                            v-model:answer="answer"
                        />
                    </template>
                </template>

                <!-- Schloss -->
                <template v-else-if="active.kind === 'lock'">
                    <p v-if="active.text && tr(active.text, locale)" class="mb-3 whitespace-pre-line text-lg text-gray-800 dark:text-gray-100">
                        {{ tr(active.text, locale) }}
                    </p>
                    <input
                        v-model="answer"
                        :maxlength="Math.max(active.code_length ?? 0, 1) + 10"
                        :placeholder="active.code_length ? '•'.repeat(active.code_length) : t('escape.enterCode')"
                        class="block w-full rounded-lg border-gray-300 text-center font-mono text-3xl tracking-[0.4em] dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                        autocomplete="off"
                        @keyup.enter="check"
                    />
                    <p v-if="active.code_length" class="mt-1 text-center text-xs text-gray-400">
                        {{ t('escape.codeLength', { n: active.code_length }) }}
                    </p>
                </template>

                <!-- Tipp -->
                <div v-if="active.kind !== 'clue' && !isSolved(active) && tr(active.hint, locale)" class="mt-4">
                    <button v-if="!showHint" type="button" class="text-sm text-indigo-600 underline dark:text-indigo-400" @click="showHint = true">
                        💡 {{ t('escape.showHint') }}
                    </button>
                    <p v-else class="rounded-lg bg-indigo-50 p-3 text-sm text-indigo-900 dark:bg-indigo-900/40 dark:text-indigo-100">
                        💡 {{ tr(active.hint, locale) }}
                    </p>
                </div>

                <!-- Aktion + Rückmeldung -->
                <div v-if="active.kind !== 'clue' && !isSolved(active)" class="mt-6 flex flex-wrap items-center justify-end gap-3">
                    <span v-if="feedback === 'wrong'" class="font-semibold text-red-600">✗ {{ t('escape.wrong') }}</span>
                    <span v-else-if="feedback === 'paused'" class="text-amber-600">{{ t('escape.paused') }}</span>
                    <span v-else-if="feedback === 'time_up'" class="text-red-600">{{ t('escape.timeUp') }}</span>
                    <button
                        type="button"
                        class="rounded-lg bg-indigo-600 px-6 py-2 text-lg text-white disabled:opacity-40"
                        :disabled="checking || cooldown || answer === undefined || answer === ''"
                        @click="check"
                    >
                        {{ active.kind === 'lock' ? '🔓 ' + t('escape.unlock') : t('escape.check') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.shake {
    animation: shake 0.3s;
}
@keyframes shake {
    0%,
    100% {
        transform: translateX(0);
    }
    25% {
        transform: translateX(-8px);
    }
    75% {
        transform: translateX(8px);
    }
}
</style>
