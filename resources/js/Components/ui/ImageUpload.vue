<script setup lang="ts">
import axios from 'axios';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';

const MAX_MB = 10;

const props = defineProps<{
    gameId: number;
    modelValue: string | null;
    mediaBase: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string | null] }>();
const { t } = useI18n();

const uploading = ref(false);
const error = ref('');
const input = ref<HTMLInputElement | null>(null);

const previewUrl = computed(() =>
    props.modelValue ? props.mediaBase + props.modelValue : '',
);

async function onFile(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;

    error.value = '';
    if (file.size > MAX_MB * 1024 * 1024) {
        error.value = t('upload.tooLarge', { mb: MAX_MB });
        if (input.value) input.value.value = '';
        return;
    }
    uploading.value = true;
    try {
        const data = new FormData();
        data.append('image', file);
        const res = await axios.post(route('media.store', props.gameId), data, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        emit('update:modelValue', res.data.path);
    } catch (e: any) {
        const status = e?.response?.status;
        error.value =
            status === 413
                ? t('upload.tooLarge', { mb: MAX_MB })
                : status === 422
                  ? (e.response.data?.errors?.image?.[0] ?? t('upload.invalid'))
                  : t('upload.failed');
    } finally {
        uploading.value = false;
        if (input.value) input.value.value = '';
    }
}

function clear() {
    emit('update:modelValue', null);
}
</script>

<template>
    <div class="space-y-2">
        <div
            v-if="previewUrl"
            class="relative inline-block overflow-hidden rounded-md border border-gray-200 dark:border-gray-700"
        >
            <img :src="previewUrl" alt="" class="max-h-40 object-contain" />
            <button
                type="button"
                @click="clear"
                class="absolute right-1 top-1 rounded-full bg-black/60 px-2 text-xs text-white"
            >
                ✕
            </button>
        </div>

        <div>
            <input
                ref="input"
                type="file"
                accept="image/png,image/jpeg,image/webp,image/gif"
                class="block text-sm text-gray-500 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-indigo-700 hover:file:bg-indigo-100 dark:file:bg-indigo-900 dark:file:text-indigo-200"
                :disabled="uploading"
                @change="onFile"
            />
            <p v-if="uploading" class="mt-1 text-xs text-gray-400">{{ t('upload.uploading') }}</p>
            <p v-if="error" class="mt-1 text-xs text-red-600">{{ error }}</p>
        </div>
    </div>
</template>
