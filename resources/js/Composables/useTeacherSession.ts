import { echo } from '@laravel/echo-vue';
import { computed, onBeforeUnmount, onMounted, reactive } from 'vue';
import type { Question } from '@/types/quiz';
import { grade, points, type Correctness } from '@/lib/scoring';

export interface ParticipantAnswer {
    answer: any;
    response_ms: number | null;
    correctness: Correctness;
    points: number;
    manualPoints?: number; // vom Lehrer vergeben (draw/free)
}

export interface LiveParticipant {
    token: string;
    label: string;
    kind: string;
    connected: boolean;
    focus: 'ok' | 'away';
    lastFocusType?: string;
    answers: Record<number, ParticipantAnswer>;
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
            .listen('.state', (p: any) => Object.assign(state, p))
            .listen('.timer', (p: any) => (state.deadline = p.deadline))
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
            .listen('.focus', (p: any) => {
                const part = ensure(p.token, p.label, 'emoji');
                part.connected = true;
                part.focus = p.type === 'returned' ? 'ok' : 'away';
                part.lastFocusType = p.type;
                persist();
            });
    }

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

    return { state, participants, list, leaderboard, answeredCount, setManualPoints, scoreOf, clear };
}
