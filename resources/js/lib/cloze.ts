// Lückentext-Parser (geteilt von Editor, Play-Scoring).
// Syntax: Lücken werden im Text mit [[Antwort]] markiert, Alternativen mit | getrennt:
//   "Die Hauptstadt von Frankreich ist [[Paris]]. Wasser gefriert bei [[0|null]] Grad."

export interface ClozeSegment {
    text?: string;
    gap?: number;
}
export interface ClozeGap {
    accepted: string[];
}

const GAP_RE = /\[\[([^\]]+)\]\]/g;

export function parseCloze(raw: string): { segments: ClozeSegment[]; gaps: ClozeGap[] } {
    const segments: ClozeSegment[] = [];
    const gaps: ClozeGap[] = [];
    let last = 0;
    let gi = 0;
    let m: RegExpExecArray | null;
    GAP_RE.lastIndex = 0;
    while ((m = GAP_RE.exec(raw)) !== null) {
        if (m.index > last) segments.push({ text: raw.slice(last, m.index) });
        gaps.push({ accepted: m[1].split('|').map((s) => s.trim()).filter(Boolean) });
        segments.push({ gap: gi++ });
        last = m.index + m[0].length;
    }
    if (last < raw.length) segments.push({ text: raw.slice(last) });
    return { segments, gaps };
}
