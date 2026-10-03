# Teil 3c – Zweck, Umfang, Module: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Parents choose the purpose («Neuer Stoff» / «Prüfungsvorbereitung»), the scope («Kurz» / «Normal» / «Ausführlich») and which modules may appear (quiz, sorting, flashcards, cloze). The last choices are prefilled per child and subject. The advanced options sit in a collapsible «Mehr Optionen».

**Architecture:**
- New columns `lessons.purpose` (`neu` | `pruefung`, default `neu`), `lessons.scope` (`kurz` | `normal` | `ausfuehrlich`, default `normal`), `lessons.modules` (json list, subset of `quiz`, `sortieren`, `karten`, `lueckentext`; null = all allowed, for old lessons).
- `modules` means **allowed** modules: a listed quiz is always built; the others are built when they fit the material, as today. At least one module must be allowed.
- Counts per scope live in `config('lessons.scope')`; prompts read them, nothing is hard-coded in the prompt files.
- The quiz becomes optional in the content (`module.quiz` may be null). The generator enforces the allowed list by nulling disallowed modules after the module step.
- «Remembered» = the settings of the parent's most recent lesson for the same child and subject, computed in `LessonController::create()`. No new table.

**Tech Stack:** Laravel 13, Pest 4, Inertia 3 + Vue 3 + Tailwind 4.

**Design:** `docs/plans/2026-10-02-neue-lernseite-design.md`, section «3c Umfang und Zweck».

**Conventions:** as in the earlier plans (German UI/comments/prompts, Swiss spelling, English test names, pint, full suite, phpstan, `npm run types:check`, `npm run check`, commits German with `Co-Authored-By`, stage by name, never commit `public/build`/`package-lock.json`, never migrate `database/database.sqlite`).

---

### Task 1: Data and upload

**Files:** migration `2026_10_02_160000_add_purpose_scope_modules_to_lessons_table.php`, `app/Models/Lesson.php`, `app/Http/Requests/StoreLessonRequest.php`, `LessonController::store`, tests.

- Columns as above; model fillable, casts (`modules` array), docblock, defaults (`purpose` `neu`, `scope` `normal`).
- A small enum-like holder is fine as constants on `Lesson` (`PURPOSES`, `SCOPES`, `MODULES`) — no new enum classes needed.
- Rules: `purpose` required in PURPOSES; `scope` required in SCOPES; `modules` required array min 1, `modules.*` distinct in MODULES. German messages («Wähle mindestens ein Lernmodul.» etc.).
- Store all three. The `upload()` test helper sends defaults (`neu`, `normal`, all four modules).
- Tests: stored values; each invalid case; unknown module rejected.

**Commit:** «Upload: Zweck, Umfang und erlaubte Module»

---

### Task 2: Quiz becomes optional

**Files:** `app/Lessons/Ai/Schemas.php` (`modules`: quiz nullable), `app/Lessons/ContentValidator.php`, `app/Lessons/Progress.php` (check only), `app/Lessons/GenerationPipeline.php` (`canRegenerate` quiz requires a quiz), `resources/js/types/lesson.ts` (`quiz: QuizQuestion[] | null`), `resources/js/components/lesson/LessonPage.vue`, `resources/js/pages/lessons/Edit.vue`, `ParentToolbar.vue`, tests.

- Validator: `module.quiz` → `present|nullable|array|min:1|max:10`; strict: `nullable|array|min:3|max:8` (the prompt sets the exact count per scope). Every consistency check that reads `$module['quiz']` must handle null (`?? []`). The «answer always in the same position» rule only when there are ≥ 3 questions.
- Content must still have at least one module: add a consistency rule «Die Seite braucht mindestens ein Lernmodul.» when all four are null.
- Frontend: quiz section only `v-if="module.quiz?.length"`; Edit.vue shows the quiz editor only when a quiz exists (no «add quiz» button — out of scope); «Quiz neu erstellen» disabled/hidden without a quiz.
- Tests: content without quiz but with cards is valid (non-strict and strict); all modules null rejected; parent page and shared page render props without quiz; regenerate quiz returns 422 without a quiz; progress summary works without quiz.

**Commit:** «Quiz ist optional»

---

### Task 3: Prompts follow purpose, scope and modules

**Files:** `config/lessons.php`, `app/Lessons/Ai/Prompts.php` (`analysis`, `context`, `quiz`), `app/Lessons/LessonGenerator.php` (`analyze`), `resources/prompts/analyse.md`, `module.md`, tests.

- Config:
```php
'scope' => [
    'kurz' => ['abschnitte' => '1–2', 'quiz' => 3, 'karten' => '4–6', 'begriffe' => '6–8', 'luecken' => '3–5'],
    'normal' => ['abschnitte' => '1–3', 'quiz' => 5, 'karten' => '5–10', 'begriffe' => '8–12', 'luecken' => '4–8'],
    'ausfuehrlich' => ['abschnitte' => '2–4', 'quiz' => 8, 'karten' => '8–15', 'begriffe' => '10–16', 'luecken' => '6–10'],
],
```
  (stay within the validator limits: abschnitte ≤ 4, quiz ≤ 8 strict, karten ≤ 20, begriffe ≤ 16.)
- `Prompts::context()` gets the lines `Zweck: Neuer Stoff` / `Zweck: Prüfungsvorbereitung`, `Umfang: kurz (1–2 Abschnitte)` etc. and `Erlaubte Lernmodule: Quiz (genau 5 Fragen), Karteikarten (5–10 Karten), …` built from config + `modules` (null = all four). The graphic step must NOT get these lines (child-safe context stays as is — check `forChild`).
- `Prompts::analysis()`: add `Zweck` and `Umfang` lines (the analysis writes the text part).
- `Prompts::quiz()` (regenerate quiz): use the scope's quiz count instead of the hard-coded 5 (IDs `q1`…`qN`).
- analyse.md: section «Zweck»: «Neuer Stoff» → explain step by step, more everyday comparisons, gentle start; «Prüfungsvorbereitung» → compact, focus on terms and definitions, end the last section with a `box` «Das Wichtigste für die Prüfung» (3–5 points). Section «Umfang»: follow the number of sections from the `Umfang` line.
- module.md: replace «Das Quiz ist immer dabei, mit genau 5 Fragen. Dazu 1–3 passende weitere» with: build only modules from «Erlaubte Lernmodule»; a listed quiz is always built with exactly the given number of questions; the other listed modules only when they fit the material; at least one module; counts from the line. Purpose: for «Prüfungsvorbereitung» more questions on definitions and typical exam traps.
- `LessonGenerator::analyze()`: after the module step, set disallowed modules to null before validation; if that leaves no module at all, it's a validation error like any other (repair path).
- Tests: context lines per purpose/scope/modules; graphic request has none of them; disallowed modules nulled even if the model returns them; regenerate quiz asks for 3 questions with scope `kurz`; old lesson (all null) gets «alle vier» and quiz count 5.

**Commit:** «Prompts: Zweck, Umfang und erlaubte Module»

---

### Task 4: Form with «Mehr Optionen» and remembered settings

**Files:** `resources/js/pages/lessons/Create.vue`, maybe `resources/js/components/LessonOptions.vue`, `LessonController::create`, tests.

- `create()` passes `lastSettings`: map `"{childId}|{subject}"` → `{purpose, scope, modules, graphics_mode}` from the parent's most recent non-deleted lesson per child+subject (one query, group in PHP; subjects compared case-insensitively and trimmed). Also `scopeInfo` from config for labels («Kurz: 3 Quizfragen, 1–2 Abschnitte»).
- Form layout: photos, Auftrag, child, subject/level stay visible. Below them a `<details>` «Mehr Optionen» (closed by default) containing: Grafiken (existing `GraphicsField`), Zweck (two radio cards with one-line explanation), Umfang (three radio cards with the counts from `scopeInfo`), Lernmodule (four checkboxes; the last checked one can't be unchecked; hint «Die KI nimmt nur Module, die zum Stoff passen. Das Quiz kommt immer, wenn es angekreuzt ist.»). The `<summary>` shows the current choice compactly, e.g. «Mehr Optionen · Neuer Stoff · Normal · 4 Module · 1 Grafik (KI)».
- Prefill: when child or subject changes and `lastSettings` has an entry, apply it — but only for fields the parent hasn't changed by hand in this form (track a `touched` set). Show a small muted note in the details «Wie bei der letzten Lernseite für {Kind} in {Fach}» when applied.
- Errors (`purpose`, `scope`, `modules`) open the details automatically.
- Tests (PHP): `lastSettings` contains the latest lesson's values per child+subject, ignores deleted lessons and other parents' lessons. Frontend: types, check, build.

**Commit:** «Formular: Mehr Optionen, Einstellungen merken»

---

### Task 5: Docs and verification

- Design doc «3c»: mark implemented; note «allowed modules», quiz optional, remembered = last lesson per child+subject, counts in config.
- Full verification incl. `npm run build`.

**Commit:** «Docs: Teil 3c»
