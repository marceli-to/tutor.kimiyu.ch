export function shuffle<T>(items: readonly T[]): T[] {
    const result = items.slice();

    for (let i = result.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [result[i], result[j]] = [result[j], result[i]];
    }

    return result;
}

// Gleiche Schwellen wie in der Vorlage
export function resultMessage(score: number, total: number): string {
    if (score === total) {
        return 'Alles richtig. Bereit für die Prüfung!';
    }

    if (score >= Math.ceil(total * 0.6)) {
        return 'Gut gemacht. Schau dir die Fehler nochmals an.';
    }

    return 'Lies den Teil oben nochmals durch und versuch es erneut.';
}

// Muss zu App\Lessons\ClozeParser::normalize passen
export function normalizeAnswer(value: string): string {
    return value.trim().toLowerCase().replace(/\s+/g, ' ');
}

// Statische Klassennamen pro Kategorie, damit Tailwind sie findet
export const categoryClasses = {
    cat1: {
        text: 'text-ls-cat1',
        bg: 'bg-ls-cat1-bg',
        border: 'border-ls-cat1',
        hover: 'hover:bg-ls-cat1-bg',
    },
    cat2: {
        text: 'text-ls-cat2',
        bg: 'bg-ls-cat2-bg',
        border: 'border-ls-cat2',
        hover: 'hover:bg-ls-cat2-bg',
    },
    cat3: {
        text: 'text-ls-cat3',
        bg: 'bg-ls-cat3-bg',
        border: 'border-ls-cat3',
        hover: 'hover:bg-ls-cat3-bg',
    },
} as const;
