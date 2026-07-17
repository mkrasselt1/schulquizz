import type { Question } from '@/types/quiz';
import type { LiveParticipant } from '@/Composables/useTeacherSession';

/**
 * Exportiert den Spielausgang aus dem Lehrer-Browser als CSV-Tabelle.
 * Erst dieser Export erzeugt eine dauerhafte Datei – bewusst lokal beim Lehrer.
 */
export function useResultExport() {
    function toCsv(rows: (string | number)[][]): string {
        return rows
            .map((r) =>
                r
                    .map((cell) => {
                        const s = String(cell ?? '');
                        return /[",;\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
                    })
                    .join(';'),
            )
            .join('\n');
    }

    function download(filename: string, content: string) {
        const blob = new Blob(['﻿' + content], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        a.click();
        URL.revokeObjectURL(url);
    }

    function exportResults(
        gameTitle: string,
        questions: Question[],
        leaderboard: (LiveParticipant & { score: number; totalTime: number })[],
    ) {
        const scored = questions.filter((q) => q.scored);
        const header = [
            'Platz',
            'Teilnehmer',
            'Punkte',
            'Zeit (ms)',
            ...scored.map((q, i) => `F${i + 1}`),
        ];
        const rows: (string | number)[][] = [header];

        leaderboard.forEach((p, idx) => {
            rows.push([
                idx + 1,
                p.label,
                p.score,
                p.totalTime,
                ...scored.map((q) => {
                    const a = p.answers[q.id];
                    if (!a) return '';
                    return a.correctness === 'correct' ? '✓' : a.correctness === 'wrong' ? '✗' : '–';
                }),
            ]);
        });

        const safe = gameTitle.replace(/[^\w-]+/g, '_').slice(0, 40) || 'quiz';
        download(`${safe}-ergebnisse.csv`, toCsv(rows));
    }

    return { exportResults };
}
