// Inhalt einer Lernseite, Schema-Version 1 (siehe app/Lessons/ContentValidator.php)

export type CategoryId = 'cat1' | 'cat2' | 'cat3';

// Herkunft eines Bausteins: von den Fotos oder aus Fachwissen ergänzt (fehlt bei alten Seiten)
export type Origin = { origin?: 'photo' | 'added' };

export type LessonBlock = Origin &
    (
        | { type: 'paragraph'; text: string }
        | { type: 'formula'; text: string; addendum?: string | null }
        | { type: 'facts'; entries: { title: string; text: string }[] }
        | {
              type: 'columns';
              entries: {
                  title: string;
                  category: CategoryId;
                  paragraphs: string[];
              }[];
          }
        | { type: 'box'; title: string; paragraphs: string[] }
        // Grafik 2 oder 3 an dieser Stelle; Grafik 1 steht oben
        | { type: 'graphic'; number: number }
    );

export type QuizQuestion = Origin & {
    id: string;
    question: string;
    options: string[];
    answer: number;
    hint?: string | null;
    explanation: string;
};

export type SortModuleData = {
    instructions?: string | null;
    categories: { id: CategoryId; label: string; sub?: string | null }[];
    terms: (Origin & {
        id: string;
        text: string;
        category: CategoryId;
        explanation?: string | null;
    })[];
};

export type FlashcardModuleData = {
    instructions?: string | null;
    entries: (Origin & { id: string; front: string; back: string })[];
};

export type ClozeSegment = { text: string } | { id: string; answers: string[] };

export type ClozeModuleData = Origin & {
    instructions?: string | null;
    segments: ClozeSegment[];
};

export type LessonContent = {
    meta: {
        title: string;
        instructions: string;
        topic: string;
        key_idea: string;
        emoji: string;
        palette: string;
    };
    sections: { title: string; blocks: LessonBlock[] }[];
    try_it: {
        experiments: string[];
        everyday_comparison?: string | null;
    } | null;
    modules: {
        // null, wenn die Eltern kein Quiz wollten
        quiz: QuizQuestion[] | null;
        sorting: SortModuleData | null;
        flashcards: FlashcardModuleData | null;
        cloze: ClozeModuleData | null;
    };
    reflect: { question: string };
};

export type PaletteColors = Record<
    | 'accent'
    | 'accent-bg'
    | 'cat1'
    | 'cat1-bg'
    | 'cat2'
    | 'cat2-bg'
    | 'cat3'
    | 'cat3-bg',
    string
>;

export type Palette = {
    label: string;
    light: PaletteColors;
    dark: PaletteColors;
};

export type LessonGraphic = {
    url: string;
    description: string;
};

// Fertige Grafiken nach Position (1 oben, 2 und 3 an ihrem Block im Inhalt)
export type LessonGraphics = Partial<Record<number, LessonGraphic>>;

// Nur für Eltern: Zustand jeder Grafik, auch der fehlgeschlagenen
export type GraphicState = {
    number: number;
    error: string | null;
    canRegenerate: boolean;
    // Von den Eltern in der Bearbeiten-Ansicht ausgeblendet
    hidden: boolean;
};

// Ergebnis einer einzelnen Antwort, für den Lernstand (Phase 4)
export type ModuleAnswer = {
    module: 'quiz' | 'sorting' | 'cloze';
    itemId: string;
    // Gewählte Option (Quiz), gewählter Korb (Sortieren) oder Eingabe (Lückentext);
    // der Server prüft selbst, ob sie stimmt
    answer: number | string;
    correct: boolean;
};
