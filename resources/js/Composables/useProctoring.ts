import axios from 'axios';
import { onBeforeUnmount, onMounted, ref } from 'vue';

type FocusType = 'tab_hidden' | 'window_blur' | 'fullscreen_exit' | 'returned';

/**
 * Best-Effort-Fokusüberwachung auf dem Schülergerät.
 * Erkennt Tab-Wechsel (Page Visibility), Fensterfokus (blur) und Vollbild-Verlassen.
 * Jedes Ereignis geht flüchtig an die Lehrkraft (POST /play/focus → Broadcast, keine DB).
 *
 * Hinweis: iOS-Safari unterstützt die Fullscreen-API nicht – dort greifen nur
 * Visibility/Blur. Pädagogisches Signal, keine manipulationssichere Sperre.
 */
export function useProctoring() {
    const away = ref(false);
    const fullscreen = ref(false);
    let lastSent = 0;

    async function send(type: FocusType) {
        // Doppelte Events in kurzer Folge unterdrücken.
        const now = Date.now();
        if (type !== 'returned' && now - lastSent < 400) return;
        lastSent = now;
        try {
            await axios.post(route('play.focus'), { type });
        } catch {
            /* Fokus-Signale sind unkritisch – Fehler ignorieren. */
        }
    }

    function markAway(type: FocusType) {
        if (!away.value) {
            away.value = true;
            send(type);
        }
    }
    function markReturned() {
        if (away.value) {
            away.value = false;
            send('returned');
        }
    }

    function onVisibility() {
        document.hidden ? markAway('tab_hidden') : markReturned();
    }
    function onBlur() {
        markAway('window_blur');
    }
    function onFocus() {
        markReturned();
    }
    function onFullscreenChange() {
        fullscreen.value = !!document.fullscreenElement;
        if (!document.fullscreenElement) {
            markAway('fullscreen_exit');
        } else {
            markReturned();
        }
    }

    async function requestFullscreen() {
        try {
            await document.documentElement.requestFullscreen?.();
            fullscreen.value = !!document.fullscreenElement;
        } catch {
            /* iOS/verweigert: still ignorieren, Best-Effort. */
        }
    }

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('blur', onBlur);
        window.addEventListener('focus', onFocus);
        document.addEventListener('fullscreenchange', onFullscreenChange);
    });
    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibility);
        window.removeEventListener('blur', onBlur);
        window.removeEventListener('focus', onFocus);
        document.removeEventListener('fullscreenchange', onFullscreenChange);
    });

    return { away, fullscreen, requestFullscreen };
}
