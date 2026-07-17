<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const props = withDefaults(
    defineProps<{
        palette?: string[];
        brushSizes?: number[];
        defaultBrush?: number;
    }>(),
    {
        palette: () => ['#000000', '#e11d48', '#2563eb', '#16a34a', '#f59e0b', '#ffffff'],
        brushSizes: () => [2, 6, 14, 28],
        defaultBrush: 6,
    },
);

const emit = defineEmits<{ change: [dataUrl: string] }>();
const { t } = useI18n();

const canvas = ref<HTMLCanvasElement | null>(null);
const color = ref(props.palette[0]);
const size = ref(props.defaultBrush);
let ctx: CanvasRenderingContext2D | null = null;
let drawing = false;

onMounted(() => {
    const c = canvas.value!;
    // Feste Zeichenauflösung für konsistente PNG-Größe.
    c.width = 600;
    c.height = 400;
    ctx = c.getContext('2d');
    if (ctx) {
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, c.width, c.height);
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
    }
});

function pos(e: PointerEvent) {
    const rect = canvas.value!.getBoundingClientRect();
    return {
        x: ((e.clientX - rect.left) / rect.width) * canvas.value!.width,
        y: ((e.clientY - rect.top) / rect.height) * canvas.value!.height,
    };
}

function start(e: PointerEvent) {
    drawing = true;
    const p = pos(e);
    ctx!.beginPath();
    ctx!.moveTo(p.x, p.y);
}
function move(e: PointerEvent) {
    if (!drawing || !ctx) return;
    const p = pos(e);
    ctx.strokeStyle = color.value;
    ctx.lineWidth = size.value;
    ctx.lineTo(p.x, p.y);
    ctx.stroke();
}
function end() {
    if (!drawing) return;
    drawing = false;
    emit('change', canvas.value!.toDataURL('image/png'));
}
function clear() {
    if (!ctx) return;
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.value!.width, canvas.value!.height);
    emit('change', canvas.value!.toDataURL('image/png'));
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-3">
            <div class="flex gap-1">
                <button
                    v-for="c in palette"
                    :key="c"
                    type="button"
                    class="h-7 w-7 rounded-full border-2"
                    :class="color === c ? 'border-gray-800 dark:border-white' : 'border-gray-300'"
                    :style="{ backgroundColor: c }"
                    @click="color = c"
                />
            </div>
            <div class="flex items-center gap-1">
                <button
                    v-for="s in brushSizes"
                    :key="s"
                    type="button"
                    class="flex h-8 w-8 items-center justify-center rounded-md border"
                    :class="size === s ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/40' : 'border-gray-300'"
                    @click="size = s"
                >
                    <span class="rounded-full bg-gray-800 dark:bg-gray-200" :style="{ width: s + 'px', height: s + 'px' }" />
                </button>
            </div>
            <button type="button" class="ml-auto text-sm text-red-500" @click="clear">
                {{ t('play.clear') }}
            </button>
        </div>
        <canvas
            ref="canvas"
            class="w-full touch-none rounded-lg border border-gray-300 bg-white dark:border-gray-600"
            @pointerdown="start"
            @pointermove="move"
            @pointerup="end"
            @pointerleave="end"
        />
    </div>
</template>
