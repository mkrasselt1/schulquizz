import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Zählt die verbleibenden Sekunden bis zu einer ISO-Deadline herunter.
 * `deadline` kann sich live ändern (Timer verlängern/setzen) – der Countdown folgt.
 */
export function useCountdown(deadline: () => string | null) {
    const remaining = ref<number | null>(null);
    let timer: ReturnType<typeof setInterval> | null = null;

    function tick() {
        const iso = deadline();
        if (!iso) {
            remaining.value = null;
            return;
        }
        const ms = new Date(iso).getTime() - Date.now();
        remaining.value = Math.max(0, Math.ceil(ms / 1000));
    }

    function start() {
        tick();
        if (!timer) timer = setInterval(tick, 250);
    }

    watch(deadline, tick);
    start();

    onBeforeUnmount(() => {
        if (timer) clearInterval(timer);
    });

    return { remaining };
}
