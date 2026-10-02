<?php

namespace App\Lessons;

use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Prüft den Inhalt einer Lernseite (Schema-Version 1).
 *
 * Nicht-strikt: was eine Seite zum Anzeigen und Bearbeiten braucht.
 * Strikt: zusätzlich die Didaktik-Regeln aus SKILL.md, die für frisch generierte Seiten gelten.
 */
class ContentValidator
{
    public const SCHEMA_VERSION = 1;

    public const BLOCK_TYPES = ['absatz', 'formel', 'fakten', 'spalten', 'box'];

    public const CATEGORIES = ['cat1', 'cat2', 'cat3'];

    // Herkunft eines Bausteins; fehlt bei alten Seiten
    public const ORIGINS = ['foto', 'ergaenzt'];

    /**
     * @param  array<string, mixed>  $content
     */
    public static function make(array $content, bool $strict = false): Validator
    {
        $validator = ValidatorFactory::make($content, self::rules($strict));

        $validator->after(function (Validator $validator) use ($content, $strict) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            (new self($validator, $content, $strict))->checkConsistency();
        });

        return $validator;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return list<string>
     */
    public static function errors(array $content, bool $strict = false): array
    {
        return array_values(self::make($content, $strict)->errors()->all());
    }

    /**
     * Fehler aufgeteilt nach Teil der Seite: «module» (Quiz usw.) und «seite» (alles andere).
     *
     * @param  array<string, mixed>  $content
     * @return array{seite: list<string>, module: list<string>}
     */
    public static function errorsByPart(array $content, bool $strict = false): array
    {
        $parts = ['seite' => [], 'module' => []];

        foreach (self::make($content, $strict)->errors()->toArray() as $key => $messages) {
            $part = str_starts_with((string) $key, 'module') ? 'module' : 'seite';
            array_push($parts[$part], ...$messages);
        }

        return $parts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function rules(bool $strict): array
    {
        return [
            'meta' => ['required', 'array'],
            'meta.titel' => ['required', 'string', 'max:120'],
            'meta.anleitung' => ['required', 'string', 'max:200'],
            'meta.thema' => ['required', 'string', 'max:80'],
            'meta.kernidee' => ['required', 'string', 'max:300'],
            'meta.emoji' => ['required', 'string', 'max:16'],
            'meta.palette' => ['required', Rule::in(Palettes::keys())],

            'abschnitte' => ['required', 'array', 'min:1', 'max:4'],
            'abschnitte.*.titel' => ['required', 'string', 'max:80'],
            'abschnitte.*.bloecke' => ['required', 'array', 'min:1', 'max:4'],
            'abschnitte.*.bloecke.*.typ' => ['required', Rule::in(self::BLOCK_TYPES)],
            'abschnitte.*.bloecke.*.herkunft' => ['sometimes', Rule::in(self::ORIGINS)],

            'probieren' => ['present', 'nullable', 'array'],
            'probieren.experimente' => ['required_with:probieren', 'array', 'min:1', 'max:3'],
            'probieren.experimente.*' => ['required', 'string', 'max:500'],
            'probieren.alltagsvergleich' => ['nullable', 'string', 'max:400'],

            'module' => ['required', 'array'],

            'module.quiz' => $strict ? ['required', 'array', 'size:5'] : ['required', 'array', 'min:1', 'max:10'],
            'module.quiz.*.id' => ['required', 'string', 'max:20'],
            'module.quiz.*.frage' => ['required', 'string', 'max:300'],
            'module.quiz.*.optionen' => ['required', 'array', 'min:3', 'max:4'],
            'module.quiz.*.optionen.*' => ['required', 'string', 'max:200'],
            'module.quiz.*.loesung' => ['required', 'integer', 'min:0', 'max:3'],
            'module.quiz.*.tipp' => ['nullable', 'string', 'max:300'],
            'module.quiz.*.erklaerung' => ['required', 'string', 'max:500'],
            'module.quiz.*.herkunft' => ['sometimes', Rule::in(self::ORIGINS)],

            'module.sortieren' => ['present', 'nullable', 'array'],
            'module.sortieren.anleitung' => ['nullable', 'string', 'max:200'],
            'module.sortieren.kategorien' => ['required_with:module.sortieren', 'array', 'min:2', 'max:3'],
            'module.sortieren.kategorien.*.id' => ['required', Rule::in(self::CATEGORIES)],
            'module.sortieren.kategorien.*.label' => ['required', 'string', 'max:40'],
            'module.sortieren.kategorien.*.sub' => ['nullable', 'string', 'max:40'],
            'module.sortieren.begriffe' => ['required_with:module.sortieren', 'array', 'min:4', 'max:16'],
            'module.sortieren.begriffe.*.id' => ['required', 'string', 'max:20'],
            'module.sortieren.begriffe.*.text' => ['required', 'string', 'max:60'],
            'module.sortieren.begriffe.*.kategorie' => ['required', Rule::in(self::CATEGORIES)],
            'module.sortieren.begriffe.*.erklaerung' => ['nullable', 'string', 'max:300'],
            'module.sortieren.begriffe.*.herkunft' => ['sometimes', Rule::in(self::ORIGINS)],

            'module.karten' => ['present', 'nullable', 'array'],
            'module.karten.anleitung' => ['nullable', 'string', 'max:200'],
            'module.karten.eintraege' => ['required_with:module.karten', 'array', 'min:3', 'max:20'],
            'module.karten.eintraege.*.id' => ['required', 'string', 'max:20'],
            'module.karten.eintraege.*.vorne' => ['required', 'string', 'max:80'],
            'module.karten.eintraege.*.hinten' => ['required', 'string', 'max:400'],
            'module.karten.eintraege.*.herkunft' => ['sometimes', Rule::in(self::ORIGINS)],

            'module.lueckentext' => ['present', 'nullable', 'array'],
            'module.lueckentext.anleitung' => ['nullable', 'string', 'max:200'],
            'module.lueckentext.segmente' => ['required_with:module.lueckentext', 'array', 'min:1', 'max:60'],
            'module.lueckentext.herkunft' => ['sometimes', Rule::in(self::ORIGINS)],

            'nachdenken' => ['required', 'array'],
            'nachdenken.frage' => ['required', 'string', 'max:400'],
        ];
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function __construct(
        private Validator $validator,
        private array $content,
        private bool $strict,
    ) {}

    private function checkConsistency(): void
    {
        $this->checkBlocks();
        $this->checkQuiz();
        $this->checkSort();
        $this->checkCloze();
        $this->checkUniqueIds();
        $this->checkSwissSpelling($this->content, '');

        if ($this->strict) {
            $this->checkStrictRules();
        }
    }

    private function fail(string $key, string $message): void
    {
        $this->validator->errors()->add($key, $message);
    }

    private function checkBlocks(): void
    {
        foreach ($this->content['abschnitte'] as $i => $section) {
            foreach ($section['bloecke'] as $j => $block) {
                $key = "abschnitte.$i.bloecke.$j";

                $valid = match ($block['typ']) {
                    'absatz' => $this->isText($block['text'] ?? null),
                    'formel' => $this->isText($block['text'] ?? null)
                        && (! isset($block['zusatz']) || is_string($block['zusatz'])),
                    'box' => $this->isText($block['titel'] ?? null) && $this->isTextList($block['absaetze'] ?? null),
                    'fakten' => $this->isList($block['eintraege'] ?? null, 1, 6, fn ($e) => $this->isText($e['titel'] ?? null) && $this->isText($e['text'] ?? null)),
                    'spalten' => $this->isList($block['eintraege'] ?? null, 2, 3, fn ($e) => $this->isText($e['titel'] ?? null)
                        && in_array($e['kategorie'] ?? null, self::CATEGORIES, true)
                        && $this->isTextList($e['absaetze'] ?? null)),
                    default => false,
                };

                if (! $valid) {
                    $this->fail($key, "Block {$key} (Typ «{$block['typ']}») ist unvollständig oder hat falsche Felder.");
                }
            }
        }
    }

    private function checkQuiz(): void
    {
        foreach ($this->content['module']['quiz'] as $i => $question) {
            $options = $question['optionen'];

            if ($question['loesung'] >= count($options)) {
                $this->fail("module.quiz.$i.loesung", "Quizfrage {$question['id']}: Die Lösung zeigt auf eine Option, die es nicht gibt.");
            }

            $normalized = array_map(fn ($o) => mb_strtolower(trim($o)), $options);
            if (count(array_unique($normalized)) !== count($options)) {
                $this->fail("module.quiz.$i.optionen", "Quizfrage {$question['id']}: Zwei Antwortoptionen sind gleich.");
            }
        }
    }

    private function checkSort(): void
    {
        $sort = $this->content['module']['sortieren'];
        if ($sort === null) {
            return;
        }

        $categoryIds = array_column($sort['kategorien'], 'id');
        if (count(array_unique($categoryIds)) !== count($categoryIds)) {
            $this->fail('module.sortieren.kategorien', 'Sortierspiel: Zwei Kategorien haben dieselbe ID.');
        }

        $used = [];
        foreach ($sort['begriffe'] as $i => $term) {
            if (! in_array($term['kategorie'], $categoryIds, true)) {
                $this->fail("module.sortieren.begriffe.$i.kategorie", "Sortierspiel: «{$term['text']}» gehört zu einer Kategorie, die es nicht gibt.");
            }
            $used[$term['kategorie']] = true;
        }

        foreach ($categoryIds as $id) {
            if (! isset($used[$id])) {
                $this->fail('module.sortieren.begriffe', "Sortierspiel: Die Kategorie {$id} hat keine Begriffe.");
            }
        }

        $texts = array_map(fn ($t) => mb_strtolower(trim($t['text'])), $sort['begriffe']);
        if (count(array_unique($texts)) !== count($texts)) {
            $this->fail('module.sortieren.begriffe', 'Sortierspiel: Ein Begriff kommt doppelt vor.');
        }
    }

    private function checkCloze(): void
    {
        $cloze = $this->content['module']['lueckentext'];
        if ($cloze === null) {
            return;
        }

        $gaps = 0;
        foreach ($cloze['segmente'] as $i => $segment) {
            $isText = is_array($segment) && array_keys($segment) === ['text'] && is_string($segment['text']);
            $isGap = is_array($segment)
                && isset($segment['id'], $segment['loesungen'])
                && count($segment) === 2
                && is_string($segment['id'])
                && $this->isTextList($segment['loesungen']);

            if ($isGap) {
                $gaps++;
            } elseif (! $isText) {
                $this->fail("module.lueckentext.segmente.$i", "Lückentext: Segment $i ist weder Text noch Lücke.");
            }
        }

        if ($gaps === 0) {
            $this->fail('module.lueckentext.segmente', 'Lückentext: Es gibt keine Lücke.');
        }
    }

    private function checkUniqueIds(): void
    {
        $module = $this->content['module'];

        $ids = [
            ...array_column($module['quiz'], 'id'),
            ...array_column($module['sortieren']['begriffe'] ?? [], 'id'),
            ...array_column($module['karten']['eintraege'] ?? [], 'id'),
            ...array_column(array_filter($module['lueckentext']['segmente'] ?? [], fn ($s) => isset($s['id'])), 'id'),
        ];

        $duplicates = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));

        if ($duplicates !== []) {
            $this->fail('module', 'Diese IDs kommen mehrfach vor: '.implode(', ', $duplicates).'.');
        }
    }

    private function checkSwissSpelling(mixed $value, string $path): void
    {
        if (is_string($value)) {
            if (str_contains($value, 'ß')) {
                $this->fail($path, "Im Feld {$path} steht ein «ß». In der Schweiz schreibt man «ss».");
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $this->checkSwissSpelling($item, $path === '' ? (string) $key : "$path.$key");
            }
        }
    }

    private function checkStrictRules(): void
    {
        $module = $this->content['module'];

        $positions = array_column($module['quiz'], 'loesung');
        if (count(array_unique($positions)) === 1) {
            $this->fail('module.quiz', 'Quiz: Die richtige Antwort steht immer an derselben Position.');
        }

        if ($module['sortieren'] === null && $module['karten'] === null && $module['lueckentext'] === null) {
            $this->fail('module', 'Neben dem Quiz braucht es mindestens ein weiteres Modul (Sortieren, Karteikarten oder Lückentext).');
        }
    }

    private function isText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isTextList(mixed $value): bool
    {
        return $this->isList($value, 1, 20, fn ($v) => $this->isText($v));
    }

    private function isList(mixed $value, int $min, int $max, callable $each): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $min || count($value) > $max) {
            return false;
        }

        foreach ($value as $item) {
            if (! $each($item)) {
                return false;
            }
        }

        return true;
    }
}
