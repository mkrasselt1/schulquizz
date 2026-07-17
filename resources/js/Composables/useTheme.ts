import { onMounted, ref } from 'vue';

const STORAGE_KEY = 'schulquizz.theme';
type Theme = 'light' | 'dark';

// Global geteilter Zustand (ein Theme für die ganze App).
const isDark = ref(false);

function apply(dark: boolean) {
    isDark.value = dark;
    document.documentElement.classList.toggle('dark', dark);
}

/**
 * Manueller Dark/Light-Umschalter. Persistiert die Wahl in localStorage.
 * Der initiale Zustand wird bereits im Blade-Head (vor dem Paint) gesetzt.
 */
export function useTheme() {
    onMounted(() => {
        isDark.value = document.documentElement.classList.contains('dark');
    });

    function toggle() {
        const dark = !isDark.value;
        apply(dark);
        localStorage.setItem(STORAGE_KEY, dark ? 'dark' : 'light');
    }

    return { isDark, toggle };
}
