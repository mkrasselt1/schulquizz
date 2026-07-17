<script setup lang="ts">
import QRCode from 'qrcode';
import { ref, watchEffect } from 'vue';

const props = withDefaults(
    defineProps<{
        value: string;
        size?: number;
    }>(),
    { size: 200 },
);

const dataUrl = ref<string>('');

watchEffect(async () => {
    if (!props.value) {
        dataUrl.value = '';
        return;
    }
    dataUrl.value = await QRCode.toDataURL(props.value, {
        width: props.size,
        margin: 1,
        errorCorrectionLevel: 'M',
    });
});
</script>

<template>
    <img
        v-if="dataUrl"
        :src="dataUrl"
        :width="size"
        :height="size"
        alt="QR-Code"
        class="rounded-md bg-white p-2"
    />
</template>
