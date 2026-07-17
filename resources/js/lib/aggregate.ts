// Baut anonyme Ergebnis-Verteilungen für die Auflösung (Board & Lehrer).
// Bekommt die rohen Antwortwerte (ohne Namen) und die volle Frage.
import type { Question } from '@/types/quiz';

export interface Distribution {
    type: string;
    // choice: [{ id, label, count, correct }]
    // slider: { values: number[] }  → Marker auf 0..100
    // estimate: { values: number[], correct: number }
    // word_cloud: [{ word, count }]
    // sonst: { correct: number, wrong: number, total: number }
    [key: string]: any;
}

export function distribution(question: Question, answers: any[], tr: (t: any) => string): Distribution {
    switch (question.type) {
        case 'choice': {
            const counts: Record<number, number> = {};
            for (const a of answers) {
                for (const id of Array.isArray(a) ? a : []) counts[id] = (counts[id] ?? 0) + 1;
            }
            return {
                type: 'choice',
                options: question.options.map((o) => ({
                    id: o.id,
                    label: tr(o.text),
                    count: counts[o.id!] ?? 0,
                    correct: o.is_correct,
                })),
            };
        }
        case 'poll_slider':
            return { type: 'poll_slider', values: answers.map((a) => Number(a)).filter((n) => !isNaN(n)) };
        case 'estimate':
            return {
                type: 'estimate',
                values: answers.map((a) => Number(a)).filter((n) => !isNaN(n)),
                correct: Number(question.config?.correct ?? 0),
                unit: question.config?.unit ?? '',
            };
        case 'word_cloud': {
            const counts: Record<string, number> = {};
            for (const a of answers) {
                for (const w of Array.isArray(a) ? a : []) {
                    const k = String(w).toLowerCase().trim();
                    if (k) counts[k] = (counts[k] ?? 0) + 1;
                }
            }
            return {
                type: 'word_cloud',
                words: Object.entries(counts)
                    .map(([word, count]) => ({ word, count }))
                    .sort((a, b) => b.count - a.count),
            };
        }
        default:
            return { type: question.type, total: answers.length };
    }
}
