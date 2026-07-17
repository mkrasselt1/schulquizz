<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ dist: Record<string, any> }>();

const maxCount = computed(() => {
    if (props.dist.type === 'choice') {
        return Math.max(1, ...props.dist.options.map((o: any) => o.count));
    }
    if (props.dist.type === 'word_cloud') {
        return Math.max(1, ...props.dist.words.map((w: any) => w.count));
    }
    return 1;
});

// Schätzfrage/Slider: Werte auf 0..100 bzw. min..max normalisieren.
const range = computed(() => {
    const values: number[] = props.dist.values ?? [];
    if (props.dist.type === 'poll_slider') return { min: 0, max: 100 };
    const all = [...values, props.dist.correct ?? 0];
    return { min: Math.min(...all, 0), max: Math.max(...all, 1) };
});
function pct(v: number): number {
    const { min, max } = range.value;
    return max === min ? 50 : ((v - min) / (max - min)) * 100;
}
</script>

<template>
    <div>
        <!-- CHOICE: Balken -->
        <div v-if="dist.type === 'choice'" class="space-y-2">
            <div v-for="o in dist.options" :key="o.id" class="flex items-center gap-2">
                <span class="w-32 truncate text-sm" :class="o.correct ? 'font-bold text-green-600' : ''">
                    {{ o.correct ? '✓ ' : '' }}{{ o.label }}
                </span>
                <div class="h-6 flex-1 rounded bg-gray-100">
                    <div
                        class="h-6 rounded"
                        :class="o.correct ? 'bg-green-500' : 'bg-indigo-500'"
                        :style="{ width: (o.count / maxCount) * 100 + '%' }"
                    />
                </div>
                <span class="w-8 text-right text-sm">{{ o.count }}</span>
            </div>
        </div>

        <!-- SLIDER / ESTIMATE: Marker auf Zahlenstrahl -->
        <div v-else-if="dist.type === 'poll_slider' || dist.type === 'estimate'" class="pt-8">
            <div class="relative h-2 rounded-full bg-gray-200">
                <div
                    v-for="(v, i) in dist.values"
                    :key="i"
                    class="absolute -top-1.5 h-5 w-5 -translate-x-1/2 rounded-full bg-indigo-500/60"
                    :style="{ left: pct(v) + '%' }"
                />
                <div
                    v-if="dist.type === 'estimate'"
                    class="absolute -top-3 h-8 w-1 -translate-x-1/2 bg-green-600"
                    :style="{ left: pct(dist.correct) + '%' }"
                    :title="'Korrekt: ' + dist.correct"
                />
            </div>
            <p v-if="dist.type === 'estimate'" class="mt-3 text-center text-sm text-green-700">
                ✓ {{ dist.correct }} {{ dist.unit }}
            </p>
        </div>

        <!-- WORD CLOUD -->
        <div v-else-if="dist.type === 'word_cloud'" class="flex flex-wrap items-center justify-center gap-3 p-4">
            <span
                v-for="w in dist.words"
                :key="w.word"
                class="font-semibold text-indigo-600"
                :style="{ fontSize: 0.9 + (w.count / maxCount) * 2 + 'rem' }"
            >
                {{ w.word }}
            </span>
        </div>

        <div v-else class="text-center text-gray-400">{{ dist.total }} Antworten</div>
    </div>
</template>
