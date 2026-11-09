// Escape-Room-Typen (Editor = volle Struktur inkl. Lösungen, Play = schülersicher).
import type { PublicQuestion, Translations } from '@/types/quiz';

export type HotspotKind = 'clue' | 'puzzle' | 'lock';

export interface EscapeHotspot {
    id: string;
    kind: HotspotKind;
    x: number; // Prozent der Bildbreite
    y: number;
    w: number;
    h: number;
    label: Translations;
    text: Translations; // clue: Hinweistext · lock: Aufforderung
    image_path: string | null; // clue: Bild
    hint: Translations; // optionaler Tipp
    question_id: number | null; // puzzle
    reward: Translations; // puzzle: Belohnung nach dem Lösen (z. B. Code-Ziffer)
    code: string; // lock
    requires: string[]; // erst sichtbar, wenn diese Hotspots gelöst sind
}

export interface EscapeRoomData {
    id: string;
    title: Translations;
    image_path: string | null;
    hotspots: EscapeHotspot[];
}

export interface EscapeConfig {
    intro: Translations;
    outro: Translations;
    time_limit: number | null; // Minuten
    show_hotspots: boolean;
    rooms: EscapeRoomData[];
}

// ---- Schülersicht (ohne Codes/Belohnungen/Lösungen) ----
export interface PublicHotspot {
    id: string;
    kind: HotspotKind;
    x: number;
    y: number;
    w: number;
    h: number;
    label: Translations;
    hint: Translations;
    requires: string[];
    text?: Translations;
    image_url?: string | null;
    question?: PublicQuestion | null;
    code_length?: number;
}

export interface PublicRoom {
    id: string;
    title: Translations;
    image_url: string | null;
    hotspots: PublicHotspot[];
}

export interface PublicEscape {
    intro: Translations;
    outro: Translations;
    time_limit: number | null;
    show_hotspots: boolean;
    rooms: PublicRoom[];
}

export const HOTSPOT_ICON: Record<HotspotKind, string> = {
    clue: '🔍',
    puzzle: '🧩',
    lock: '🔒',
};

/** Sekunden → "mm:ss" (bzw. "h:mm:ss"). */
export function formatClock(totalSeconds: number): string {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');
    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}
