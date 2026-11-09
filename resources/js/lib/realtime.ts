import { echo } from '@laravel/echo-vue';

/**
 * Ruft `callback` auf, sobald Nachrichten verpasst worden sein könnten:
 *  - die WebSocket-Verbindung wurde nach einem Abbruch wiederhergestellt (WLAN-Aussetzer)
 *  - das Gerät/der Tab ist wieder sichtbar (Bildschirmsperre, App-Wechsel)
 * Gibt eine Aufräumfunktion zurück.
 */
export function onResync(callback: () => void): () => void {
    let wasConnected = false;
    let pusherConnection: any = null;

    const onState = (states: { previous: string; current: string }) => {
        if (states.current === 'connected') {
            if (wasConnected) callback();
            wasConnected = true;
        }
    };

    try {
        pusherConnection = (echo() as any).connector?.pusher?.connection;
        if (pusherConnection) {
            wasConnected = pusherConnection.state === 'connected';
            pusherConnection.bind('state_change', onState);
        }
    } catch {
        /* Echo nicht verfügbar – nur Sichtbarkeit nutzen */
    }

    const onVisible = () => {
        if (document.visibilityState === 'visible') callback();
    };
    document.addEventListener('visibilitychange', onVisible);

    return () => {
        document.removeEventListener('visibilitychange', onVisible);
        try {
            pusherConnection?.unbind('state_change', onState);
        } catch {
            /* ignore */
        }
    };
}

/** localStorage ohne Absturz bei vollem Speicher / privatem Modus. */
export function safeSet(key: string, value: string): boolean {
    try {
        localStorage.setItem(key, value);
        return true;
    } catch {
        return false;
    }
}

export function safeGet<T>(key: string, fallback: T): T {
    try {
        const raw = localStorage.getItem(key);
        return raw ? (JSON.parse(raw) as T) : fallback;
    } catch {
        return fallback;
    }
}

const TWO_DAYS = 2 * 24 * 3600 * 1000;
const PLAYED_AT = 'schulquizz.playedAt';
const STUDENT_PREFIXES = ['schulquizz.answered.', 'schulquizz.escape.'];

/**
 * Datensparsamkeit im Browser (läuft bei jedem App-Start):
 *  - Lehrer-Live-Daten (schulquizz.session.*) älter als 2 Tage löschen,
 *  - Schülerdaten (Pseudonym, letzte Antwort, Escape-Fortschritt) löschen, wenn auf diesem
 *    Gerät seit 2 Tagen nicht mehr gespielt wurde.
 */
export function pruneLocalData(): void {
    try {
        const cutoff = Date.now() - TWO_DAYS;
        const playedAt = Number(localStorage.getItem(PLAYED_AT)) || 0;
        const studentStale = playedAt < cutoff;
        for (const key of Object.keys(localStorage)) {
            if (key.startsWith('schulquizz.session.')) {
                const saved = safeGet<any>(key, {});
                if (!saved?.savedAt || saved.savedAt < cutoff) localStorage.removeItem(key);
            } else if (
                studentStale &&
                (key === 'schulquizz.participant' || key === PLAYED_AT || STUDENT_PREFIXES.some((p) => key.startsWith(p)))
            ) {
                localStorage.removeItem(key);
            }
        }
    } catch {
        /* ignore */
    }
}

/** Beim Spielen: Daten früherer Spiele auf diesem Gerät entfernen (geteilte Klassentablets). */
export function forgetOtherGames(sessionKey: string): void {
    try {
        safeSet(PLAYED_AT, String(Date.now()));
        for (const key of Object.keys(localStorage)) {
            if (STUDENT_PREFIXES.some((p) => key.startsWith(p)) && !key.includes(`.${sessionKey}.`)) {
                localStorage.removeItem(key);
            }
        }
    } catch {
        /* ignore */
    }
}
