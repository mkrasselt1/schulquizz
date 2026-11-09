// Abgleich mit der Serveruhr: Schüler-Tablets gehen oft falsch. Countdowns rechnen deshalb
// mit "Serverzeit" = Gerätezeit + gemessener Abweichung (aus server_time in State/Timer-Events).

let offsetMs = 0;

export function syncServerClock(serverIso?: string | null): void {
    if (!serverIso) return;
    const server = Date.parse(serverIso);
    if (!Number.isNaN(server)) offsetMs = server - Date.now();
}

export function serverNow(): number {
    return Date.now() + offsetMs;
}
