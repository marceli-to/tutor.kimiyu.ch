// Inhalt einer Lernseite, Schema-Version 1 (siehe app/Lessons/ContentValidator.php)

export type CategoryId = 'cat1' | 'cat2' | 'cat3';

// Herkunft eines Bausteins: von den Fotos oder aus Fachwissen ergänzt (fehlt bei alten Seiten)
export type Origin = { herkunft?: 'foto' | 'ergaenzt' };

export type LessonBlock = Origin &
    (
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
        | { typ: 'box'; titel: string; absaetze: string[] }
        // Grafik 2 oder 3 an dieser Stelle; Grafik 1 steht oben
        | { typ: 'grafik'; nr: number }
    );

export type QuizQuestion = Origin & {
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
    begriffe: (Origin & {
        id: string;
        text: string;
        kategorie: CategoryId;
        erklaerung?: string | null;
    })[];
};

export type FlashcardModuleData = {
    anleitung?: string | null;
    eintraege: (Origin & { id: string; vorne: string; hinten: string })[];
};

export type ClozeSegment =
    | { text: string }
    | { id: string; loesungen: string[] };

export type ClozeModuleData = Origin & {
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

// Fertige Grafiken nach Position (1 oben, 2 und 3 an ihrem Block im Inhalt)
export type LessonGraphics = Partial<Record<number, LessonHero>>;

// Nur für Eltern: Zustand jeder Grafik, auch der fehlgeschlagenen
export type GraphicState = {
    nr: number;
    error: string | null;
    canRegenerate: boolean;
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
