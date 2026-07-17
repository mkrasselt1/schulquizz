// Gemeinsame Typen für Fragen/Antworten – von Editor, Play und Board genutzt.

export type QuestionType =
    | 'choice'
    | 'cloze'
    | 'free_text'
    | 'draw'
    | 'sort'
    | 'poll_slider'
    | 'estimate'
    | 'word_cloud';

export type Translations = Record<string, string>;

export interface AnswerOption {
    id?: number;
    position: number;
    mode: 'text' | 'image';
    text: Translations;
    image_path: string | null;
    is_correct: boolean;
}

export interface Question {
    id: number;
    position: number;
    type: QuestionType;
    prompt_mode: 'text' | 'image';
    prompt_text: Translations;
    prompt_image_path: string | null;
    time_limit: number | null;
    points: number;
    scored: boolean;
    reveal_after: boolean | null;
    config: Record<string, any>;
    options: AnswerOption[];
}

// Schülersichere Darstellung (vom Server via PublicQuestion, ohne Lösungen).
export interface PublicOption {
    id: number;
    mode: 'text' | 'image';
    text: Translations;
    image_url: string | null;
}

export interface PublicQuestion {
    id: number;
    type: QuestionType;
    prompt_mode: 'text' | 'image';
    prompt_text: Translations;
    prompt_image_url: string | null;
    points: number;
    scored: boolean;
    time_limit: number | null;
    config: Record<string, any>;
    options?: PublicOption[];
}

export const UNSCORED_TYPES: QuestionType[] = ['poll_slider', 'word_cloud'];

export const QUESTION_TYPE_META: Record<QuestionType, { icon: string; key: string }> = {
    choice: { icon: '☑️', key: 'choice' },
    cloze: { icon: '␣', key: 'cloze' },
    free_text: { icon: '✍️', key: 'free_text' },
    draw: { icon: '🎨', key: 'draw' },
    sort: { icon: '↕️', key: 'sort' },
    poll_slider: { icon: '🎚️', key: 'poll_slider' },
    estimate: { icon: '🔢', key: 'estimate' },
    word_cloud: { icon: '☁️', key: 'word_cloud' },
};

/** Lokalisierten Wert aus einem Translations-Objekt lesen (mit Fallback). */
export function tr(translations: Translations | undefined, locale: string, base = 'de'): string {
    if (!translations) return '';
    return translations[locale] ?? translations[base] ?? Object.values(translations)[0] ?? '';
}
