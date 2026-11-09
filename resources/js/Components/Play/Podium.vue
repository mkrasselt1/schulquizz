<script setup lang="ts">
import { computed } from 'vue';

interface Entry {
    label: string;
    score: number;
    /** Optionale Anzeige statt Punkten (z. B. Escape-Zeit) */
    display?: string;
}

const props = defineProps<{ entries: Entry[] }>();

const top3 = computed(() => props.entries.slice(0, 3));
const rest = computed(() => props.entries.slice(3));

// Podest-Reihenfolge: 2. links, 1. Mitte, 3. rechts
const order = computed(() => {
    const [first, second, third] = top3.value;
    return [
        { rank: 2, entry: second, height: 'h-24', medal: '🥈' },
        { rank: 1, entry: first, height: 'h-32', medal: '🥇' },
        { rank: 3, entry: third, height: 'h-16', medal: '🥉' },
    ].filter((x) => x.entry);
});
</script>

<!--
    Bewusst OHNE dark:-Varianten: Diese Komponente wird immer auf eine HELLE Fläche
    gesetzt (auch auf der Tafel), deshalb feste, gut lesbare Farben für hellen Grund.
-->
<template>
    <div class="text-gray-800">
        <div class="flex items-end justify-center gap-3">
            <div v-for="col in order" :key="col.rank" class="flex w-24 flex-col items-center">
                <div class="mb-1 text-3xl">{{ col.medal }}</div>
                <div class="truncate text-center text-lg font-semibold text-gray-900">
                    {{ col.entry.label }}
                </div>
                <div class="text-sm text-gray-500">{{ col.entry.display ?? col.entry.score }}</div>
                <div
                    class="mt-1 flex w-full items-start justify-center rounded-t-lg bg-gradient-to-b from-indigo-400 to-indigo-600 pt-2 font-black text-white"
                    :class="col.height"
                >
                    {{ col.rank }}
                </div>
            </div>
        </div>

        <ol v-if="rest.length" class="mx-auto mt-8 max-w-md space-y-1">
            <li
                v-for="(e, i) in rest"
                :key="i"
                class="flex justify-between rounded-md bg-gray-100 px-4 py-1.5 text-gray-700"
            >
                <span>{{ i + 4 }}. {{ e.label }}</span>
                <span class="font-medium">{{ e.display ?? e.score }}</span>
            </li>
        </ol>
    </div>
</template>
