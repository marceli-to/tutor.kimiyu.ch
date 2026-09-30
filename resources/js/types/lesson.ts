// Inhalt einer Lernseite, Schema-Version 1 (siehe app/Lessons/ContentValidator.php)

export type CategoryId = 'cat1' | 'cat2' | 'cat3';

export type LessonBlock =
    | { typ: 'absatz'; text: string }
    | { typ: 'formel'; text: string; zusatz?: string | null }
    | { typ: 'fakten'; eintraege: { titel: string; text: string }[] }
    | {
          typ: 'spalten';
          eintraege: {
              titel: string;
              kategorie: CategoryId;
              absaetze: string[];
          }[];
      }
    | { typ: 'box'; titel: string; absaetze: string[] };

export type QuizQuestion = {
    id: string;
    frage: string;
    optionen: string[];
    loesung: number;
    tipp?: string | null;
    erklaerung: string;
};

export type SortModuleData = {
    anleitung?: string | null;
    kategorien: { id: CategoryId; label: string; sub?: string | null }[];
    begriffe: {
        id: string;
        text: string;
        kategorie: CategoryId;
        erklaerung?: string | null;
    }[];
};

export type FlashcardModuleData = {
    anleitung?: string | null;
    eintraege: { id: string; vorne: string; hinten: string }[];
};

export type ClozeSegment =
    | { text: string }
    | { id: string; loesungen: string[] };

export type ClozeModuleData = {
    anleitung?: string | null;
    segmente: ClozeSegment[];
};

export type LessonContent = {
    meta: {
        titel: string;
        anleitung: string;
        thema: string;
        kernidee: string;
        emoji: string;
        palette: string;
    };
    abschnitte: { titel: string; bloecke: LessonBlock[] }[];
    probieren: {
        experimente: string[];
        alltagsvergleich?: string | null;
    } | null;
    module: {
        quiz: QuizQuestion[];
        sortieren: SortModuleData | null;
        karten: FlashcardModuleData | null;
        lueckentext: ClozeModuleData | null;
    };
    nachdenken: { frage: string };
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

export type LessonHero = {
    url: string;
    beschreibung: string;
};

// Ergebnis einer einzelnen Antwort, für den Lernstand (Phase 4)
export type ModuleAnswer = {
    module: 'quiz' | 'sortieren' | 'lueckentext';
    itemId: string;
    // Gewählte Option (Quiz), gewählter Korb (Sortieren) oder Eingabe (Lückentext);
    // der Server prüft selbst, ob sie stimmt
    answer: number | string;
    correct: boolean;
};
