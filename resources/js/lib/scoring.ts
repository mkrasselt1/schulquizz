// Reine Wertungsfunktionen – laufen im Lehrer-Browser (Quelle der Wahrheit).
// Kennt die Lösungen (nur der autorisierten Lehrkraft ausgeliefert).
import type { Question } from '@/types/quiz';

export type Correctness = 'correct' | 'wrong' | 'manual' | 'none';

function norm(s: string): string {
    return String(s ?? '')
        .toLowerCase()
        .trim()
        .replace(/\s+/g, ' ');
}

/** Levenshtein-Ähnlichkeit (0..1). */
function similarity(a: string, b: string): number {
    a = norm(a);
    b = norm(b);
    if (!a && !b) return 1;
    if (!a || !b) return 0;
    const m = a.length;
    const n = b.length;
    const d: number[][] = Array.from({ length: m + 1 }, () => new Array(n + 1).fill(0));
    for (let i = 0; i <= m; i++) d[i][0] = i;
    for (let j = 0; j <= n; j++) d[0][j] = j;
    for (let i = 1; i <= m; i++) {
        for (let j = 1; j <= n; j++) {
            const cost = a[i - 1] === b[j - 1] ? 0 : 1;
            d[i][j] = Math.min(d[i - 1][j] + 1, d[i][j - 1] + 1, d[i - 1][j - 1] + cost);
        }
    }
    return 1 - d[m][n] / Math.max(m, n);
}

/** Ist die Antwort korrekt? (`manual` = Lehrer bewertet, `none` = ungewertet) */
export function grade(question: Question, answer: any): Correctness {
    if (!question.scored) return 'none';

    switch (question.type) {
        case 'poll_slider':
        case 'word_cloud':
            return 'none';
        case 'draw':
            return 'manual';
        case 'choice': {
            const correct = question.options.filter((o) => o.is_correct).map((o) => o.id!);
            const given: number[] = Array.isArray(answer) ? answer : [];
            if (given.length === 0) return 'wrong';
            const a = [...correct].sort();
            const b = [...given].sort();
            return a.length === b.length && a.every((x, i) => x === b[i]) ? 'correct' : 'wrong';
        }
        case 'sort': {
            const correct = [...question.options].sort((x, y) => x.position - y.position).map((o) => o.id!);
            const given: number[] = Array.isArray(answer) ? answer : [];
            return correct.length === given.length && correct.every((x, i) => x === given[i])
                ? 'correct'
                : 'wrong';
        }
        case 'cloze': {
            const accepted: string[] = question.config?.accepted ?? [];
            return accepted.some((acc) => norm(acc) === norm(answer)) ? 'correct' : 'wrong';
        }
        case 'free_text': {
            const accepted: string[] = question.config?.accepted ?? [];
            const threshold = question.config?.threshold ?? 0.8;
            return accepted.some((acc) => similarity(acc, String(answer ?? '')) >= threshold)
                ? 'correct'
                : 'wrong';
        }
        case 'estimate': {
            const correct = Number(question.config?.correct ?? 0);
            const tol = Number(question.config?.tolerance ?? 0);
            return Math.abs(Number(answer) - correct) <= tol ? 'correct' : 'wrong';
        }
        default:
            return 'none';
    }
}

/**
 * Punkte für eine Antwort. Schnelligkeit zählt: skaliert mit Restzeit (base..base/2).
 * Schätzfrage: Nähe-basiert. Manuelle/ungewertete Typen: 0 (Punkte setzt der Lehrer separat).
 */
export function points(question: Question, answer: any, responseMs: number | null): number {
    if (!question.scored) return 0;
    const base = question.points ?? 0;

    if (question.type === 'estimate') {
        const correct = Number(question.config?.correct ?? 0);
        const dist = Math.abs(Number(answer) - correct);
        const span = Math.max(Math.abs(correct), 1) * 0.5; // Halbierung bei 50% Abweichung
        const proximity = Math.max(0, 1 - dist / (span || 1));
        return Math.round(base * proximity * speedFactor(question, responseMs));
    }

    if (grade(question, answer) !== 'correct') return 0;
    return Math.round(base * speedFactor(question, responseMs));
}

function speedFactor(question: Question, responseMs: number | null): number {
    if (!question.time_limit || responseMs == null) return 1;
    const frac = 1 - responseMs / (question.time_limit * 1000);
    return 0.5 + 0.5 * Math.min(1, Math.max(0, frac));
}
