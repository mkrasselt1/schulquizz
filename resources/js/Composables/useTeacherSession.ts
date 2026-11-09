import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import type { Question } from '@/types/quiz';
import { grade, points, type Correctness } from '@/lib/scoring';
import { syncServerClock } from '@/lib/serverClock';

export interface ParticipantAnswer {
    answer: any;
    response_ms: number | null;
    correctness: Correctness;
    points: number;
    manualPoints?: number; // vom Lehrer vergeben (draw/free)
}

/** Escape-Room-Fortschritt (vom Server gemeldet, nur im Lehrer-Browser gehalten). */
export interface EscapeProgress {
    room: number;
    solved: string[];
    wrong: number;
    escapedMs: number | null;
}

export interface LiveParticipant {
    token: string;
    label: string;
    kind: string;
    connected: boolean;
    focus: 'ok' | 'away';
    lastFocusType?: string;
    answers: Record<number, ParticipantAnswer>;
    escape?: EscapeProgress;
}

/**
 * Live-Zustand einer Session im Lehrer-Browser. Quelle der Wahrheit.
 * Sammelt Roster (Presence), Antworten & Fokus (privater Channel), wertet lokal und
 * spiegelt alles in localStorage (übersteht Reload). Löschbar bei Spielende/Export.
 */
export function useTeacherSession(
    sessionKey: string,
    questions: Question[],
    initialState: Record<string, any> = {},
) {
    const storageKey = `schulquizz.session.${sessionKey}`;
    const questionMap = new Map(questions.map((q) => [q.id, q]));

    const participants = reactive<Record<string, LiveParticipant>>({});
    const state = reactive<Record<string, any>>({ ...initialState });

    // Wiederherstellen (Reload-Schutz).
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (saved?.participants) {
            for (const [token, p] of Object.entries(saved.participants)) {
                participants[token] = { ...(p as LiveParticipant), connected: false };
            }
        }
    } catch {
        /* ignore */
    }

    function persist() {
        localStorage.setItem(storageKey, JSON.stringify({ participants }));
    }

    function ensure(token: string, label: string, kind: string): LiveParticipant {
        if (!participants[token]) {
            participants[token] = { token, label, kind, connected: false, focus: 'ok', answers: {} };
        }
        return participants[token];
    }

    const channelName = `session.${sessionKey}`;

    // ---- Öffentlicher Steuer-Channel (State/Timer/Ende) ----
    function bindControl() {
        echo()
            .channel(channelName)
            .listen('.state', (p: any) => {
                syncServerClock(p.server_time);
                Object.assign(state, p);
            })
            .listen('.timer', (p: any) => {
                syncServerClock(p.server_time);
                state.deadline = p.deadline;
            })
            .listen('.ended', () => (state.status = 'ended'));
    }

    // ---- Privater Lehrer-Channel: Roster (joined/left), Antworten & Fokus ----
    function bindTeacher() {
        echo()
            .private(`${channelName}.teacher`)
            .listen('.joined', (p: any) => {
                ensure(p.token, p.label, p.kind).connected = true;
                persist();
            })
            .listen('.left', (p: any) => {
                if (participants[p.token]) participants[p.token].connected = false;
                persist();
            })
            .listen('.answer', (p: any) => {
                const q = questionMap.get(p.question_id);
                if (!q) return;
                const part = ensure(p.token, p.label, p.kind);
                part.connected = true;
                part.answers[p.question_id] = {
                    answer: p.answer,
                    response_ms: p.response_ms ?? null,
                    correctness: grade(q, p.answer),
                    points: points(q, p.answer, p.response_ms ?? null),
                };
                persist();
            })
            .listen('.escape_progress', (p: any) => {
                const part = ensure(p.token, p.label, p.kind);
                part.connected = true;
                if (!part.escape) part.escape = { room: 0, solved: [], wrong: 0, escapedMs: null };
                const e = part.escape;
                if (p.correct) {
                    if (!e.solved.includes(p.hotspot_id)) e.solved.push(p.hotspot_id);
                    e.room = Math.max(e.room, Number(p.room_index ?? 0));
                    if (p.result === 'escaped' && e.escapedMs === null) e.escapedMs = p.elapsed_ms ?? 0;
                } else {
                    e.wrong++;
                }
                persist();
            })
            .listen('.focus', (p: any) => {
                const part = ensure(p.token, p.label, 'emoji');
                part.connected = true;
                part.focus = p.type === 'returned' ? 'ok' : 'away';
                part.lastFocusType = p.type;
                persist();
            });
    }

    syncServerClock(initialState.server_time);

    onMounted(() => {
        bindControl();
        bindTeacher();
    });
    onBeforeUnmount(() => {
        echo().leave(channelName);
        echo().leave(`${channelName}.teacher`);
    });

    // ---- Abgeleitete Werte ----
    function scoreOf(p: LiveParticipant): number {
        return Object.values(p.answers).reduce(
            (sum, a) => sum + (a.manualPoints ?? a.points),
            0,
        );
    }
    function timeOf(p: LiveParticipant): number {
        return Object.values(p.answers).reduce((sum, a) => sum + (a.response_ms ?? 0), 0);
    }

    const list = computed(() => Object.values(participants));

    const leaderboard = computed(() =>
        [...list.value]
            .map((p) => ({ ...p, score: scoreOf(p), totalTime: timeOf(p) }))
            .sort((a, b) => b.score - a.score || a.totalTime - b.totalTime),
    );

    /** Escape-Rangliste: Entkommene nach Zeit, dann weitester Raum, meiste Lösungen. */
    const escapeRanking = computed(() =>
        [...list.value].sort((a, b) => {
            const ea = a.escape;
            const eb = b.escape;
            const ta = ea?.escapedMs ?? Infinity;
            const tb = eb?.escapedMs ?? Infinity;
            if (ta !== tb) return ta - tb;
            if ((eb?.room ?? 0) !== (ea?.room ?? 0)) return (eb?.room ?? 0) - (ea?.room ?? 0);
            if ((eb?.solved.length ?? 0) !== (ea?.solved.length ?? 0))
                return (eb?.solved.length ?? 0) - (ea?.solved.length ?? 0);
            return (ea?.wrong ?? 0) - (eb?.wrong ?? 0);
        }),
    );

    /** Noch nicht bewertete Mal-Antworten (zählen bis dahin 0 Punkte). */
    const openGradings = computed(() =>
        list.value.reduce(
            (n, p) =>
                n +
                Object.entries(p.answers).filter(
                    ([qid, a]) => questionMap.get(Number(qid))?.type === 'draw' && a.manualPoints === undefined,
                ).length,
            0,
        ),
    );

    function answeredCount(questionId: number): number {
        return list.value.filter((p) => p.answers[questionId] !== undefined).length;
    }

    function setManualPoints(token: string, questionId: number, pts: number) {
        const a = participants[token]?.answers[questionId];
        if (a) {
            a.manualPoints = pts;
            persist();
        }
    }

    function clear() {
        localStorage.removeItem(storageKey);
        for (const k of Object.keys(participants)) delete participants[k];
    }

    return {
        state,
        participants,
        list,
        leaderboard,
        escapeRanking,
        openGradings,
        answeredCount,
        setManualPoints,
        scoreOf,
        clear,
    };
}
