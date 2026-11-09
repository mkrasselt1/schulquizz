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
