# Teil 4 – Kosten senken: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Cut the API cost per lesson from about $1.05 to about $0.50 without losing quality, and make the cost per step visible so each change can be measured.

**Architecture:** Each `ModelRequest` resolves its own model and effort from `config/lessons.php` by step name (exact match first, then the prefix before the first `-`, then the global `services.anthropic` default). `ClaudeLanguageModel` no longer holds a fixed model/effort. The check step becomes one call over the whole page that returns only corrections (JSON pointer + new value). A new `Corrections` class applies them one by one and keeps only those that leave the content valid. The Kosten page gains a per-step table.

**Tech Stack:** Laravel 13, Pest 4, Inertia 3 + Vue 3, Anthropic PHP SDK (`anthropic-ai/sdk`).

**Design:** `docs/plans/2026-10-02-neue-lernseite-design.md`, section «Teil 4».

**Conventions in this repo**
- Comments, prompts, UI text and user-facing messages are German (Schweizer Rechtschreibung: no «ß», quotes «…»). Test names are English.
- Run single tests with `php artisan test --compact --filter='<name>'`. Full suite: `php artisan test --compact` (179 tests green at start).
- Format PHP with `vendor/bin/pint --dirty` before each commit. Frontend: `npm run types:check` and `npm run check`.
- Branch: `feature/neue-lernseite`. Commit messages German, end with the `Co-Authored-By` line.

---

### Task 1: Model and effort per step in the config

**Files:**
- Modify: `config/lessons.php`
- Modify: `app/Lessons/Ai/ModelRequest.php`
- Create: `tests/Unit/Lessons/ModelRequestTest.php`

**Step 1: Write the failing test**

`tests/Unit/Lessons/ModelRequestTest.php`:

```php
<?php

use App\Lessons\Ai\ModelRequest;

function modelRequest(string $step): ModelRequest
{
    return new ModelRequest(step: $step, system: '', prompt: '', schema: [], maxTokens: 100);
}

beforeEach(function () {
    config()->set('services.anthropic.model', 'claude-opus-5-5');
    config()->set('services.anthropic.effort', 'high');
    config()->set('lessons.models', [
        'module' => ['model' => 'claude-sonnet-5-5', 'effort' => 'medium'],
        'grafik' => ['model' => null, 'effort' => 'medium'],
    ]);
});

it('uses the model and effort configured for the step', function () {
    expect(modelRequest('module')->model())->toBe('claude-sonnet-5-5')
        ->and(modelRequest('module')->effort())->toBe('medium');
});

it('falls back to the prefix of the step', function () {
    expect(modelRequest('grafik-reparatur')->effort())->toBe('medium');
});

it('falls back to the global default for unknown steps and empty values', function () {
    expect(modelRequest('analyse')->model())->toBe('claude-opus-5-5')
        ->and(modelRequest('analyse')->effort())->toBe('high')
        ->and(modelRequest('grafik')->model())->toBe('claude-opus-5-5');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=ModelRequestTest`
Expected: FAIL with `Call to undefined method App\Lessons\Ai\ModelRequest::model()`

**Step 3: Implement**

Add to `app/Lessons/Ai/ModelRequest.php` (below the constructor; update the `$step` docblock to list `analyse, module, neu-quiz, reparatur-*, pruefung, grafik, grafik-reparatur`):

```php
    public function model(): string
    {
        return $this->setting('model') ?? config('services.anthropic.model');
    }

    public function effort(): string
    {
        return $this->setting('effort') ?? config('services.anthropic.effort');
    }

    /**
     * Einstellung für diesen Schritt aus config/lessons.php: zuerst der genaue Schritt
     * («grafik-reparatur»), dann der Teil vor dem Bindestrich («grafik»).
     */
    private function setting(string $key): ?string
    {
        foreach ([$this->step, strtok($this->step, '-')] as $name) {
            $value = config("lessons.models.{$name}.{$key}");

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
```

Add to `config/lessons.php` after `'max_tokens'`:

```php
    /*
    | Modell und Effort pro Schritt. Leer: globaler Standard aus services.anthropic.
    | Fotos lesen und interaktive Grafiken bauen braucht Opus; strukturiertes Schreiben
    | und Prüfen aus vorhandenem Stoff schafft Sonnet zum halben Preis.
    | Schritte ohne eigenen Eintrag nehmen den Teil vor dem Bindestrich (grafik-reparatur → grafik).
    */
    'models' => [
        'analyse' => ['model' => env('LESSON_MODEL_ANALYSE'), 'effort' => env('LESSON_EFFORT_ANALYSE')],
        'module' => ['model' => env('LESSON_MODEL_MODULE', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULE', 'medium')],
        'neu-quiz' => ['model' => env('LESSON_MODEL_MODULE', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULE', 'medium')],
        'reparatur' => ['model' => env('LESSON_MODEL_MODULE', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_MODULE', 'medium')],
        'pruefung' => ['model' => env('LESSON_MODEL_PRUEFUNG', 'claude-sonnet-5-5'), 'effort' => env('LESSON_EFFORT_PRUEFUNG', 'medium')],
        'grafik' => ['model' => env('LESSON_MODEL_GRAFIK'), 'effort' => env('LESSON_EFFORT_GRAFIK', 'medium')],
    ],
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=ModelRequestTest`
Expected: PASS (3 tests)

**Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add config/lessons.php app/Lessons/Ai/ModelRequest.php tests/Unit/Lessons/ModelRequestTest.php
git commit -m "Modell und Effort pro Schritt konfigurierbar"
```

---

### Task 2: Use the per-step model in the API client and the cost log

**Files:**
- Modify: `app/Lessons/Ai/ClaudeLanguageModel.php:34-60`
- Modify: `app/Providers/AppServiceProvider.php:25-37`
- Modify: `app/Lessons/LessonGenerator.php` (method `call`, the `'model' =>` line)
- Test: `tests/Feature/Lessons/LessonGenerationTest.php`

**Step 1: Write the failing test**

Add to `tests/Feature/Lessons/LessonGenerationTest.php` (after «shows a clear message when the api fails»):

```php
it('logs the model of the step when a call fails without a response', function () {
    config()->set('lessons.models.analyse', ['model' => 'claude-test-analyse', 'effort' => 'high']);
    $this->fake->push('analyse', new ModelException('Die KI war nicht erreichbar.'));

    upload();

    expect(Lesson::sole()->generations()->where('step', 'analyse')->value('model'))->toBe('claude-test-analyse');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter='logs the model of the step'`
Expected: FAIL, logged model is `claude-opus-5-5` (the global default)

**Step 3: Implement**

In `LessonGenerator::call()`, replace

```php
            'model' => $response->model ?? config('services.anthropic.model'),
```

with

```php
            'model' => $response->model ?? $request->model(),
```

In `ClaudeLanguageModel`:
- Remove the constructor parameters `private string $model,` and `private string $effort,`.
- In `generate()` use `model: $request->model(),` and `effort: Effort::from($request->effort()),`.
- Update the class docblock: add a line `- Modell und Effort kommen pro Schritt aus dem ModelRequest (config/lessons.php)`.

In `AppServiceProvider::register()` remove the `model:` and `effort:` arguments.

**Step 4: Run tests**

Run: `php artisan test --compact --filter=LessonGenerationTest`
Expected: PASS

Also run `vendor/bin/phpstan analyse --memory-limit=1G` (larastan is installed; `phpstan.neon` exists). Expected: no new errors.

**Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Lessons/Ai/ClaudeLanguageModel.php app/Providers/AppServiceProvider.php app/Lessons/LessonGenerator.php tests/Feature/Lessons/LessonGenerationTest.php
git commit -m "API-Client nimmt Modell und Effort aus dem Schritt"
```

---

### Task 3: `Corrections` – apply check corrections safely

**Files:**
- Create: `app/Lessons/Corrections.php`
- Create: `tests/Unit/Lessons/CorrectionsTest.php`

Rules:
- `pfad` is a JSON pointer into the page (`/module/quiz/0/tipp`), indices 0-based.
- Only **existing** values can be replaced. No adding or removing keys or list items.
- Paths ending in `/id` are rejected (IDs link answers in the Lernstand).
- If the old value is a string, `wert` is used as-is. Otherwise `wert` must be JSON (`2`, `["A","B"]`, `{"…":…}`), else rejected.
- Corrections are applied one by one. Each is kept only if the content still passes `ContentValidator::errors()`.

**Step 1: Write the failing tests**

`tests/Unit/Lessons/CorrectionsTest.php`:

```php
<?php

use App\Lessons\Corrections;
use Database\Factories\LessonFactory;

function correction(string $pfad, string $wert): array
{
    return ['pfad' => $pfad, 'wert' => $wert, 'bereich' => 'Test', 'aenderung' => 'Test.'];
}

beforeEach(fn () => $this->content = LessonFactory::fixture('fotosynthese'));

it('replaces a text value', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/tipp', 'Neuer Tipp.')]);

    expect($result['content']['module']['quiz'][0]['tipp'])->toBe('Neuer Tipp.')
        ->and($result['applied'])->toHaveCount(1)
        ->and($result['rejected'])->toBe([]);
});

it('decodes json for values that are not strings', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/loesung', '1')]);

    expect($result['content']['module']['quiz'][0]['loesung'])->toBe(1);
});

it('rejects paths that do not exist', function (string $pfad) {
    $result = Corrections::apply($this->content, [correction($pfad, 'x')]);

    expect($result['content'])->toBe($this->content)
        ->and($result['rejected'])->toHaveCount(1);
})->with(['/module/quiz/99/tipp', '/meta/farbe', '', 'meta/titel']);

it('rejects changes to ids', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/id', 'q9')]);

    expect($result['content'])->toBe($this->content);
});

it('rejects invalid json for non-string values', function () {
    $result = Corrections::apply($this->content, [correction('/module/quiz/0/loesung', 'eins')]);

    expect($result['content'])->toBe($this->content);
});

it('keeps valid corrections and drops the ones that break the content', function () {
    $result = Corrections::apply($this->content, [
        correction('/module/quiz/0/loesung', '99'),
        correction('/meta/kernidee', 'Pflanzen machen aus Licht Zucker.'),
    ]);

    expect($result['content']['module']['quiz'][0]['loesung'])->toBe($this->content['module']['quiz'][0]['loesung'])
        ->and($result['content']['meta']['kernidee'])->toBe('Pflanzen machen aus Licht Zucker.')
        ->and($result['applied'])->toHaveCount(1)
        ->and($result['rejected'])->toHaveCount(1);
});
```

Before running, open `database/fixtures/lessons/fotosynthese.json` and confirm `module.quiz[0]` has the keys `id`, `tipp` and `loesung` and `loesung` is not already `1`. If it is, use `2` in «decodes json».

**Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=CorrectionsTest`
Expected: FAIL with `Class "App\Lessons\Corrections" not found`

**Step 3: Implement**

`app/Lessons/Corrections.php`:

```php
<?php

namespace App\Lessons;

/**
 * Wendet die Korrekturen des Prüf-Schritts auf eine Lernseite an.
 *
 * Die Prüfung schickt nur, was sie ändert (JSON-Pointer + neuer Wert), statt die ganze Seite neu zu schreiben.
 * Jede Korrektur wird einzeln angewendet und nur behalten, wenn die Seite gültig bleibt.
 */
class Corrections
{
    /**
     * @param  array<string, mixed>  $content
     * @param  list<array{pfad: string, wert: string, bereich: string, aenderung: string}>  $corrections
     * @return array{content: array<string, mixed>, applied: list<array<string, string>>, rejected: list<array<string, string>>}
     */
    public static function apply(array $content, array $corrections): array
    {
        $applied = [];
        $rejected = [];

        foreach ($corrections as $correction) {
            $candidate = self::replace($content, (string) ($correction['pfad'] ?? ''), (string) ($correction['wert'] ?? ''));

            if ($candidate === null || ContentValidator::errors($candidate) !== []) {
                $rejected[] = $correction;

                continue;
            }

            $content = $candidate;
            $applied[] = $correction;
        }

        return ['content' => $content, 'applied' => $applied, 'rejected' => $rejected];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>|null null, wenn der Pfad nicht existiert oder nicht geändert werden darf
     */
    private static function replace(array $content, string $pointer, string $value): ?array
    {
        if (! str_starts_with($pointer, '/') || str_ends_with($pointer, '/id')) {
            return null;
        }

        $keys = array_map(
            fn (string $key) => str_replace(['~1', '~0'], ['/', '~'], $key),
            explode('/', substr($pointer, 1)),
        );

        $target = &$content;
        foreach ($keys as $key) {
            if (! is_array($target) || ! array_key_exists(array_is_list($target) && ctype_digit($key) ? (int) $key : $key, $target)) {
                return null;
            }

            $target = &$target[array_is_list($target) && ctype_digit($key) ? (int) $key : $key];
        }

        if (is_string($target)) {
            $target = $value;

            return $content;
        }

        $decoded = json_decode($value, true);

        if ($decoded === null && trim($value) !== 'null') {
            return null;
        }

        $target = $decoded;

        return $content;
    }
}
```

**Step 4: Run tests**

Run: `php artisan test --compact --filter=CorrectionsTest`
Expected: PASS (9 tests, including the dataset)

**Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Lessons/Corrections.php tests/Unit/Lessons/CorrectionsTest.php
git commit -m "Korrekturen der Prüfung einzeln und sicher anwenden"
```

---

### Task 4: Schema and fake response for the new check

**Files:**
- Modify: `app/Lessons/Ai/Schemas.php` (method `part`, method `changes`, new method `checkResult`)
- Modify: `app/Lessons/Ai/FakeLanguageModel.php` (method `defaultResponse`)
- Modify: `tests/Unit/Lessons/SchemasTest.php`

**Step 1: Update the tests**

In `tests/Unit/Lessons/SchemasTest.php`:
- In the «only uses features …» dataset, replace the two `check …` rows with `'check' => fn () => Schemas::checkResult(),`.
- In «matches the responses of the fake model», replace the two `pruefung-*` rows with `['pruefung', fn () => Schemas::checkResult()],`.

**Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=SchemasTest`
Expected: FAIL with `Call to undefined method App\Lessons\Ai\Schemas::checkResult()`

**Step 3: Implement**

In `Schemas.php`:
- `part()` loses the `$withChanges` parameter (only the repair uses it now):

```php
    public static function part(string $part): array
    {
        return self::object([$part => $part === 'seite' ? self::page() : self::modules()]);
    }
```

- Replace `changes()` with `checkResult()` (public, with the same docblock style as the other public methods):

```php
    /**
     * Prüfung: nur die Korrekturen, nicht die ganze Seite.
     *
     * @return array<string, mixed>
     */
    public static function checkResult(): array
    {
        return self::object([
            'korrekturen' => [
                'type' => 'array',
                'items' => self::object([
                    'pfad' => ['type' => 'string', 'description' => 'JSON-Pointer auf den Wert, z. B. /module/quiz/2/loesung oder /abschnitte/0/bloecke/1/text. Indizes 0-basiert.'],
                    'wert' => ['type' => 'string', 'description' => 'Neuer Wert. Text direkt; Zahlen, Listen und Objekte als JSON, z. B. 1 oder ["A","B","C"]'],
                    'bereich' => ['type' => 'string', 'description' => 'z. B. «Quiz, Frage 3» oder «Sortierspiel»'],
                    'aenderung' => ['type' => 'string', 'description' => 'Was geändert wurde und warum, ein Satz'],
                ]),
            ],
        ]);
    }
```

In `FakeLanguageModel::defaultResponse()` replace the two `pruefung-*` arms with:

```php
            'pruefung' => ['korrekturen' => []],
```

**Step 4: Run tests**

Run: `php artisan test --compact --filter=SchemasTest`
Expected: PASS. (`LessonGenerationTest` fails now, fixed in Task 5.)

**Step 5: Commit** skip: commit together with Task 5 so every commit stays green

---

### Task 5: One check call that returns corrections

**Files:**
- Modify: `app/Lessons/Ai/Prompts.php` (method `check`)
- Modify: `resources/prompts/pruefung.md`
- Modify: `app/Lessons/LessonGenerator.php` (method `check`)
- Modify: `tests/Feature/Lessons/LessonGenerationTest.php` (tests at lines ~80, 141–188)

**Step 1: Update the tests**

In `tests/Feature/Lessons/LessonGenerationTest.php`:

Line ~80, the step list becomes:

```php
    expect($lesson->generations()->pluck('step')->all())->toBe(['analyse', 'module', 'pruefung', 'grafik'])
```

Replace the four check tests («skips the check …» through «keeps going when the check call fails») with:

```php
it('skips the check when it is switched off', function () {
    config()->set('lessons.check_enabled', false);

    upload();

    expect($this->fake->requestsFor('pruefung'))->toBe([])
        ->and(Lesson::sole()->status)->toBe(LessonStatus::Review);
});

it('checks the whole page in one call', function () {
    upload();

    $request = $this->fake->requestsFor('pruefung');
    expect($request)->toHaveCount(1)
        ->and($request[0]->prompt)->toContain('"module"')
        ->and($request[0]->prompt)->toContain('"abschnitte"');
});

it('applies the corrections of the check and lists them for the parents', function () {
    $this->fake->push('pruefung', ['korrekturen' => [
        ['pfad' => '/module/quiz/0/tipp', 'wert' => 'Denk an die Zutaten, nicht an das Ergebnis.', 'bereich' => 'Quiz, Frage 1', 'aenderung' => 'Tipp präzisiert.'],
    ]]);

    upload();

    $lesson = Lesson::sole();
    expect($lesson->check_notes)->toBe([['bereich' => 'Quiz, Frage 1', 'aenderung' => 'Tipp präzisiert.']])
        ->and($lesson->content['module']['quiz'][0]['tipp'])->toBe('Denk an die Zutaten, nicht an das Ergebnis.');
});

it('drops corrections that would break the content', function () {
    $this->fake->push('pruefung', ['korrekturen' => [
        ['pfad' => '/module/quiz/0/loesung', 'wert' => '99', 'bereich' => 'Quiz, Frage 1', 'aenderung' => 'Lösung korrigiert.'],
    ]]);

    upload();

    expect(Lesson::sole()->content)->toBe(LessonFactory::fixture('fotosynthese'))
        ->and(Lesson::sole()->check_notes)->toBe([])
        ->and(Lesson::sole()->status)->toBe(LessonStatus::Review);
});

it('keeps going when the check call fails', function () {
    $this->fake->push('pruefung', new ModelException('Die KI ist gerade ausgelastet.', retryable: false));

    upload();

    expect(Lesson::sole()->status)->toBe(LessonStatus::Review)
        ->and(Lesson::sole()->content)->toBe(LessonFactory::fixture('fotosynthese'))
        ->and(Lesson::sole()->generations()->where('step', 'pruefung')->value('status'))->toBe('error');
});
```

Also search the rest of `tests/` for `pruefung-` and adjust: `grep -rn "pruefung-" tests app resources`.

**Step 2: Run to verify they fail**

Run: `php artisan test --compact --filter=LessonGenerationTest`
Expected: FAIL (step list still shows `pruefung-seite`, `pruefung-module`)

**Step 3: Implement**

`Prompts::check()` loses the `$part` parameter:

```php
    /**
     * Prüfung der ganzen Seite in einem Aufruf. Die Antwort enthält nur Korrekturen.
     *
     * @param  array<string, mixed>  $content
     */
    public static function check(Lesson $lesson, array $content): ModelRequest
    {
        return new ModelRequest(
            step: 'pruefung',
            system: self::load('pruefung'),
            prompt: implode("\n\n", [
                self::context($lesson),
                "Lernseite:\n".self::json($content),
            ]),
            schema: Schemas::checkResult(),
            maxTokens: config('lessons.max_tokens.pruefung'),
        );
    }
```

In `config/lessons.php` set `'pruefung' => 16000,` (no full page in the output any more; thinking still needs room).

`LessonGenerator::check()`:

```php
    /**
     * Zweiter Durchgang, der fachliche Fehler korrigiert. Die Prüfung liefert nur Korrekturen;
     * ungültige werden verworfen. Scheitert der Aufruf, bleibt die Seite wie sie ist.
     */
    public function check(Lesson $lesson): void
    {
        try {
            $corrections = $this->call($lesson, Prompts::check($lesson, $lesson->content))->data['korrekturen'] ?? [];
        } catch (ModelException $e) {
            Log::warning('Prüf-Call fehlgeschlagen', ['lesson' => $lesson->id, 'error' => $e->detail ?? $e->getMessage()]);

            return;
        }

        $result = Corrections::apply($lesson->content, $corrections);

        if ($result['rejected'] !== []) {
            Log::warning('Korrekturen der Prüfung verworfen', ['lesson' => $lesson->id, 'rejected' => $result['rejected']]);
        }

        $lesson->update([
            'title' => $result['content']['meta']['titel'],
            'content' => $result['content'],
            'check_notes' => array_map(
                fn (array $c) => ['bereich' => $c['bereich'], 'aenderung' => $c['aenderung']],
                $result['applied'],
            ),
        ]);
    }
```

(Add `use App\Lessons\Corrections;` is not needed, same namespace `App\Lessons`.)

`resources/prompts/pruefung.md`: replace the first paragraph and the last two paragraphs (keep the numbered list 1–5 unchanged):

```markdown
Du bist Fachlehrperson auf der Sekundarstufe I in der Schweiz und prüfst eine Lernseite, bevor die Eltern sie freigeben. Du bekommst die Zusammenfassung des Stoffs und die ganze Lernseite als JSON (Textteil und Module).

…(Liste 1–5 unverändert)…

Korrigiere nur, was falsch oder missverständlich ist. Ändere nichts, was korrekt ist: kein Umformulieren aus Geschmacksgründen, keine neuen Fragen, keine neuen oder gelöschten Einträge, IDs nie ändern.

Gib **nur die Korrekturen** zurück, nicht die Seite. Jede Korrektur ersetzt genau einen bestehenden Wert:

- `pfad`: JSON-Pointer auf den Wert in der Lernseite, Indizes 0-basiert, z. B. `/module/quiz/2/loesung`, `/module/quiz/0/optionen`, `/abschnitte/1/bloecke/0/text`.
- `wert`: der neue Wert. Text direkt (ohne Anführungszeichen); Zahlen, Listen und Objekte als JSON, z. B. `1` oder `["Licht","Wasser","CO₂"]`. Wenn du die Lösung einer Quizfrage änderst und dafür die Optionen umstellst, ersetze `optionen` und `loesung` je mit einer eigenen Korrektur.
- `bereich` und `aenderung`: wo und was, in einem Satz. Die Eltern sehen das.

Wenn alles stimmt, ist `korrekturen` leer.
```

**Step 4: Run tests**

Run: `php artisan test --compact`
Expected: all green

**Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Lessons/Ai/Schemas.php app/Lessons/Ai/FakeLanguageModel.php app/Lessons/Ai/Prompts.php app/Lessons/LessonGenerator.php resources/prompts/pruefung.md config/lessons.php tests/
git commit -m "Prüfung in einem Aufruf, nur Korrekturen statt ganzer Seite"
```

---

### Task 6: Shorter graphic prompt (one example)

The graphic prompt sends both fixture heroes in full (~15k characters). One is enough as a quality reference. Small saving on input, mostly less to read.

**Files:**
- Modify: `app/Lessons/Ai/Prompts.php` (method `hero`)
- Modify: `resources/prompts/grafik.md:90` («Zwei gelungene Grafiken» → «Eine gelungene Grafik»)
- Test: `tests/Feature/Lessons/LessonGenerationTest.php`

**Step 1: Write the failing test**

```php
it('sends one example graphic to the graphic step', function () {
    upload();

    $system = $this->fake->requestsFor('grafik')[0]->system;
    expect($system)->toContain(LessonFactory::fixture('fotosynthese')['meta']['titel'])
        ->and($system)->not->toContain(LessonFactory::fixture('oekosystem')['meta']['titel']);
});
```

**Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter='one example graphic'`
Expected: FAIL (both titles present)

**Step 3: Implement**

In `Prompts::hero()` replace the `$examples` block:

```php
        // Ein Beispiel reicht als Massstab; jedes weitere kostet nur Input
        $example = '### '.LessonFactory::fixture('fotosynthese')['meta']['titel']."\n\n```json\n".self::json(LessonFactory::fixture('fotosynthese.hero'))."\n```";
```

and `'{{BEISPIELE}}' => $example`. In `grafik.md` change line 90 to: `Eine gelungene Grafik als Qualitätsmassstab. Übernimm Qualität und Machart, nicht den Inhalt.`

**Step 4: Run tests**

Run: `php artisan test --compact --filter=LessonGenerationTest`
Expected: PASS

**Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Lessons/Ai/Prompts.php resources/prompts/grafik.md tests/Feature/Lessons/LessonGenerationTest.php
git commit -m "Grafik-Prompt mit einem Beispiel statt zwei"
```

---

### Task 7: Cost per step on the Kosten page

**Files:**
- Modify: `app/Http/Controllers/CostController.php`
- Modify: `resources/js/pages/Costs.vue`
- Test: `tests/Feature/Lessons/ProgressTest.php` (the costs test lives there)

**Step 1: Write the failing test**

Add next to «sums up the costs per month and lesson» in `ProgressTest.php`:

```php
    it('shows the average cost per step and model', function () {
        $this->lesson->generations()->createMany([
            ['step' => 'grafik', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'input_tokens' => 10_000, 'output_tokens' => 20_000, 'cost_usd' => 0.6],
            ['step' => 'grafik', 'model' => 'claude-opus-5-5', 'status' => 'ok', 'input_tokens' => 12_000, 'output_tokens' => 10_000, 'cost_usd' => 0.4],
            ['step' => 'module', 'model' => 'claude-sonnet-5-5', 'status' => 'ok', 'input_tokens' => 7_000, 'output_tokens' => 4_000, 'cost_usd' => 0.05],
        ]);

        $this->actingAs($this->user)->get(route('costs'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('steps.0', [
                    'step' => 'grafik',
                    'model' => 'claude-opus-5-5',
                    'calls' => 2,
                    'inputTokens' => 11_000,
                    'outputTokens' => 15_000,
                    'avgUsd' => 0.5,
                    'totalUsd' => 1.0,
                ])
                ->where('steps.1.step', 'module')
            );
    });
```

**Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter='average cost per step'`
Expected: FAIL, prop `steps` missing

**Step 3: Implement**

In `CostController::__invoke()`, before `return`:

```php
        // Pro Schritt und Modell, teuerste zuerst: zeigt, wo sich Sparen lohnt
        $steps = $generations
            ->groupBy(fn (Generation $g) => "{$g->step}|{$g->model}")
            ->map(fn (Collection $items) => [
                'step' => $items->first()->step,
                'model' => $items->first()->model,
                'calls' => $items->count(),
                'inputTokens' => (int) round($items->avg('input_tokens')),
                'outputTokens' => (int) round($items->avg('output_tokens')),
                'avgUsd' => round((float) $items->avg('cost_usd'), 3),
                'totalUsd' => round((float) $items->sum('cost_usd'), 2),
            ])
            ->sortByDesc('totalUsd')
            ->values();
```

Add `'steps' => $steps,` to the props and remove `'model' => config('services.anthropic.model'),` (there is no single model any more).

In `Costs.vue`:
- Remove `model: string;` from the props; change the Heading description to `Was die Lernseiten bei der Claude API gekostet haben. Enthält auch fehlgeschlagene Aufrufe.` (plain string, no template literal).
- Add to props:

```ts
    steps: {
        step: string;
        model: string;
        calls: number;
        inputTokens: number;
        outputTokens: number;
        avgUsd: number;
        totalUsd: number;
    }[];
```

- Add a section between «Pro Monat» and «Pro Lernseite», copying the table markup of «Pro Monat» exactly (same classes):
  - `<h2 class="font-medium">Pro Schritt</h2>` and below it `<p class="text-sm text-muted-foreground">Durchschnitt pro Aufruf.</p>`
  - Columns: Schritt (`{{ s.step }}` with `<span class="block text-muted-foreground">{{ s.model }}</span>` below, like the child line in «Pro Lernseite»), Aufrufe, Ø Input, Ø Output (both with `number()`), Ø Kosten (`usd(s.avgUsd, 3)`), Total (`usd(s.totalUsd)`).
  - `v-for="s in steps" :key="`${s.step}|${s.model}`"`, section `v-if="steps.length"`.

**Step 4: Run tests and frontend checks**

Run: `php artisan test --compact --filter=ProgressTest` → PASS
Run: `npm run types:check` → no errors
Run: `npm run check` → no errors (run `npm run check:fix` for formatting, then re-run)

**Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add app/Http/Controllers/CostController.php resources/js/pages/Costs.vue tests/Feature/Lessons/ProgressTest.php
git commit -m "Kostenübersicht pro Schritt und Modell"
```

---

### Task 8: Env example and full verification

**Files:**
- Modify: `.env.example`
- Modify: `docs/deployment.md` (only if it lists the `ANTHROPIC_*` variables: `grep -n ANTHROPIC docs/deployment.md`)

**Step 1:** Below `ANTHROPIC_EFFORT=high` in `.env.example` add:

```
# Modell/Effort pro Schritt (leer = Standard aus config/lessons.php)
# LESSON_MODEL_ANALYSE=
# LESSON_MODEL_MODULE=claude-sonnet-5-5
# LESSON_MODEL_PRUEFUNG=claude-sonnet-5-5
# LESSON_MODEL_GRAFIK=
# LESSON_EFFORT_GRAFIK=medium
```

Mirror this in `docs/deployment.md` if the variables are documented there.

**Step 2: Verify everything**

```bash
php artisan test --compact
vendor/bin/phpstan analyse --memory-limit=1G
npm run types:check
npm run check
npm run build
```

Expected: all green. Then open https://tutor.kimiyu.ch.test/kosten and confirm the «Pro Schritt» table shows the existing prod rows (`grafik` first).

**Step 3: Commit**

```bash
git add .env.example docs/deployment.md
git commit -m "Env-Beispiel für Modelle pro Schritt"
```

---

### Task 9: Measure with real lessons (manual, costs money – ask first)

Not automated. Needs the user's OK because each lesson costs real API money (estimated $0.50–1.00 each).

1. With `LESSON_FAKE_AI=false` and a queue worker running (`php artisan queue:work`), create 3–4 lessons from real photos covering different subjects (one with graphic, one without).
2. On `/kosten`, compare «Pro Schritt» against the baseline in the design doc (grafik $0.61, module $0.13, pruefung $0.23 for two calls, analyse $0.07).
3. Review the pages yourself: quiz correctness, graphic works and looks as good as before, check notes make sense.
4. If graphic quality drops at `medium`, set `LESSON_EFFORT_GRAFIK=high` and note it in the design doc. If module quality drops on Sonnet, set `LESSON_MODEL_MODULE=claude-opus-5-5` with `LESSON_EFFORT_MODULE=medium`.
5. Record measured numbers in the design doc under «Teil 4 → Vorgehen und Ziel».
