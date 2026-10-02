# Teil 5 – Fachprofile: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Lesson pages fit the subject. Same page layout and building blocks, but per profile a prompt addendum, an allowed set of blocks and modules, and defaults. Profiles (stored values): `science` (today), `general`, `languages`, `math`, `geometry`, `german`. Labels shown to people stay German (Naturwissenschaften, Allgemein, Sprachen, Mathematik, Geometrie, Deutsch).

**Architecture:**
- `App\Lessons\Profile` (enum, string-backed) knows per profile: label, allowed block types, allowed modules, extra prompt file `resources/prompts/profile/{value}.md` (English file names: `science.md`, `general.md`, `languages.md`, `math.md`, `geometry.md`, `german.md`; German prose inside), example fixture, defaults (graphics mode, modules), and for `languages` the speech language.
- `lessons.profile` (nullable string): null = automatic. The automatic profile is derived from the subject via `config('lessons.profiles')` (case-insensitive map from German subject names to profile values) **after step 1 of the analysis**, because the subject may only be known then (AI detection). The resolved profile is stored on the lesson.
- **Schema size is the main constraint.** The API rejects structured-output grammars that compile too large (see `docs/plans/2026-10-02-einfach-erweitert.md` and the split in commit 54abf90). Therefore `Schemas::page(Profile)` and `Schemas::modules(Profile)` only contain the blocks/modules allowed for that profile. A profile never gets all new blocks at once. After every schema change, run the API probe (see below) for **every** profile.
- `ContentValidator` becomes profile-aware: blocks/modules not allowed for the lesson's profile are errors on fresh generations (strict) and tolerated on display (non-strict), so old content never breaks.
- New answer types (`exercises`, `find_the_mistake`) are checked on the server in `App\Lessons\Progress::check()`, like today's modules, and stored via `App\Actions\Progress\RecordAnswer` (today it passes only scalar answers to `Progress::check()` — `find_the_mistake` needs an object answer, extend it). The browser never decides correctness.

**Tech Stack:** Laravel 13, Pest 4, Inertia 3 + Vue 3 + Tailwind 4. New dependency (approved by the user): `katex` (npm) for formulas in the math, geometry and science profiles.

**Design:** `docs/plans/2026-10-02-neue-lernseite-design.md`, section «Teil 5».

**Conventions:** as in `docs/plans/2026-10-02-code-style.md`: English identifiers, array/JSON keys and enum values (content, schemas, profile values, module and block names), English code comments, indentation with tabs (`composer format` = PHP-CS-Fixer for PHP, `npm run check:fix` for the frontend; JSON/YAML/Markdown keep spaces); German stays in UI text, labels, validation messages and prompt prose (Swiss spelling); English test names, full suite, phpstan 0, `npm run types:check`, `npm run check`, commits German with `Co-Authored-By`, stage by name, never commit `public/build`/`package-lock.json` — except `package.json` + the lockfile change for `katex` in Task 3, which must be committed —, never migrate `database/database.sqlite`).

**State of the code base (after the code-style refactoring, `docs/plans/2026-10-02-code-style.md`):** content keys, schema keys, block types, module names, steps and patterns are English (e.g. `sections/blocks/type`, `paragraph/formula/facts/columns/box/graphic`, `quiz/sorting/flashcards/cloze`, `try_it`, `origin: photo|added`, pattern `calculator`); prompt files are `analysis.md`, `modules.md`, `check.md`, `repair.md`, `graphic.md`, `graphic-repair.md`. Class names changed: `GraphicPattern`, `GraphicValidator`, `GraphicDocument`, `GraphicFrame.vue`, `Prompts::graphic()/graphicRepair()`, graphic fixtures `*.graphic.json`. There is no `LessonGenerator` any more: generation steps are actions in `app/Actions/Generation` (`AnalyzeLesson`, `CheckLesson`, `GenerateGraphic`, `RegenerateQuizQuestions`, `CallModel`), write use cases in `app/Actions/Lessons` (`CreateLesson`, `UpdateLessonContent`, …) and `app/Actions/Progress/RecordAnswer`; Inertia props come from page-data classes in `app/Http/PageData` (`CreateLessonPage`, `EditLessonPage`, `LessonPage`, …); controllers stay slim (authorize, call one action or page-data class, respond). Old stored content is migrated by `LegacyKeys` — new keys of this plan need no mapping there.

**Fixtures:** each profile task adds its own hand-written fixture (`passe-compose`, `dreisatz`, `winkel-parallelen`, `das-dass`) under `database/fixtures/lessons/`, listed in a new `LessonFactory::PROFILE_FIXTURES` (keep `FIXTURES` = the two science fixtures for existing datasets). English keys like the existing fixtures, German content with the same quality and Swiss spelling, `origin: "photo"` everywhere. The profile uses its fixture as the example in the page prompt.

**API probe:** `/private/tmp/claude-501/-Users-marceli-to-Jamon-digital-Webroot-tutor-kimiyu-ch/e7dd5486-15dd-473f-918f-d4d584f0eca8/scratchpad/probe.php` sends schemas with `max_tokens: 1` (< $0.01 per run). Extend its candidate list to loop over `Profile::cases()` for `part('page', $profile)` and `modulesResult($profile)`. Every candidate must print OK before committing a schema change. Also extend the size-guard test in `tests/Unit/Lessons/SchemasTest.php` to every profile.

---

### Task 1: Profile framework (no new blocks yet)

**Files:** `app/Lessons/Profile.php` (new enum); `config/lessons.php` (`profiles` map); migration `2026_10_02_180000_add_profile_to_lessons_table.php`; `app/Models/Lesson.php`; `app/Http/Requests/StoreLessonRequest.php`; `app/Actions/Lessons/CreateLesson.php`; `app/Http/PageData/CreateLessonPage.php` (profile options); `app/Actions/Generation/AnalyzeLesson.php`; `app/Lessons/Ai/Prompts.php`; `app/Lessons/Ai/Schemas.php`; `app/Lessons/ContentValidator.php`; `resources/prompts/profile/science.md`, `general.md`; `LessonOptions.vue`/Create.vue (advanced mode: select «Fachprofil»); tests.

- Enum cases: `Science = 'science'`, `General = 'general'`, `Languages = 'languages'`, `Math = 'math'`, `Geometry = 'geometry'`, `German = 'german'`. Methods: `label()` (German), `blocks(): list<string>` (base: paragraph, formula, facts, columns, box, graphic), `modules(): list<string>` (base: quiz, sorting, flashcards, cloze), `promptFile()`, `fixture(): string` (example for the page prompt; `fotosynthese` for science/general until a profile has its own), `defaultGraphicsMode()`, `allowsExperiments(): bool` (`try_it` only for science), `speechLang(?string $subject): ?string` (only languages; Task 2).
- Config map (keys = German subject names as typed or detected, values = profile values), e.g. `'biologie' => 'science', 'chemie' => …, 'physik' => …, 'natur und technik' => …, 'mathematik' => 'math', 'geometrie' => 'geometry', 'deutsch' => 'german', 'französisch' => 'languages', 'englisch' => 'languages', 'italienisch' => 'languages'`; everything else `general`. `Profile::forSubject(?string)`.
- `lessons.profile` nullable. Request: `profile` `nullable|in:…` (advanced mode only; easy mode sends nothing). `AnalyzeLesson::handle()`: after the analysis call (subject known), set `profile = $lesson->profile ?? Profile::forSubject($lesson->subject)`.
- Prompts: `context()` and `pageRequest()` add `Fachprofil: {label}` plus the content of the profile prompt file (`analysis()` step 1 doesn't need it). The graphic step gets the profile label only (graphic patterns differ per subject), not the full addendum. `science.md` = what's special today (experiments, everyday comparison); `general.md` = no experiments, timelines and maps welcome.
- `modules.md`/`analysis.md`: «Halte dich an den Abschnitt «Fachprofil», er geht den allgemeinen Regeln vor.»
- Schemas: `page(?Profile $profile = null)` and `modules(?Profile $profile = null)` filter the block `anyOf` and module properties by the profile (null = today's full set, used for display/validation helpers). `part('page', $profile)`, `modulesResult($profile)`, repair schemas pass the lesson's profile (today `part(string $part)`, `modulesResult()`, `page()`, `modules()` take no profile). Module properties not allowed for a profile are **omitted** from the schema (not nullable), and `AnalyzeLesson` (and `RegenerateQuizQuestions` for the quiz) fills them with null afterwards so stored content keeps the same shape.
- `try_it`: omitted from the page schema when `! allowsExperiments()`; `AnalyzeLesson` sets `try_it = null`.
- Validator: `ContentValidator::make/errors/errorsByPart($content, $strict, ?Profile $profile = null)`; in strict mode report blocks/modules outside the profile.
- Advanced form: select «Fachprofil» (Automatisch + 6 German labels, values = profile values) next to Fach.
- Tests: subject → profile mapping (incl. detected subject), explicit profile wins, prompt contains the addendum, graphic prompt only the label, schema per profile omits disallowed parts, `AnalyzeLesson` nulls them, strict validator rejects a disallowed block, probe OK for all profiles.

**Commit:** «Fachprofile: Grundgerüst»

---

### Task 2: Languages (`languages`)

**Files:** `Profile` (blocks + `vocabulary`, `conjugation`; speech lang), `Schemas`, `ContentValidator`, `Progress`, `RecordAnswer`, `SharedLessonController::answer`, `LessonView`, `resources/prompts/profile/languages.md`, `resources/js/types/lesson.ts`, `LessonBlock.vue`, `FlashcardModule.vue`, `ClozeModule.vue` (+ `ClozeParser` server side), new `resources/js/components/lesson/SpeakButton.vue`, `Edit.vue`, fixture `passe-compose.json`, tests.

- Block `vocabulary`: `{ title: string|null, entries: [{ foreign, german, info: string|null }] }` (info e.g. «m.», «Verb», example sentence). 4–30 entries.
- Block `conjugation`: `{ verb, tense, forms: [{ person, form }] }` (6 rows).
- `languages.md`: vocab tables instead of long explanations; grammar rule in a `box` with 2–3 example sentences; flashcards `front` = foreign word, `back` = German (+ short example); cloze for verb forms and vocabulary; no experiments; graphic default `none`.
- Speech: `Profile::speechLang($subject)` → `fr-FR` / `en-GB` / `it-IT`; `LessonView` passes `speechLang` (null otherwise). `SpeakButton` uses `window.speechSynthesis` with that `lang`, hidden when unsupported or no voice for the language; on vocabulary rows (foreign word) and card fronts. No network.
- Flashcards: in a languages lesson a toggle «Deutsch zuerst» flips which side shows first (UI only, per page view).
- Cloze accents: `ClozeParser::check(string $answer, array $solutions): 'correct'|'almost'|'wrong'` — `almost` when it matches after removing diacritics (`Normalizer` + strip combining marks) but not exactly (case-insensitive as today). `isCorrect()` stays (true only for `correct`). `Progress::check()`/`RecordAnswer` return `?bool` today — extend them so the answer endpoint can return `{correct, almost}`; ClozeModule shows «Fast – achte auf den Akzent: {Musterlösung}». Progress counts `almost` as not correct.
- Edit.vue: table editors for `vocabulary` (add/remove rows) and `conjugation` (6 fixed rows).
- Fixture `passe-compose.json` (Französisch, 2. Sek): vocabulary + conjugation + box + flashcards + cloze + quiz.
- Tests: schema for languages contains the two blocks, others don't; validator rules; `almost` detection (é/e, à/a, ü/u, case); answer endpoint `almost`; `speechLang` in shared view props for Französisch only; fixture validates strictly with profile languages; probe OK.

**Commit:** «Fachprofil Sprachen: Vokabeln, Konjugation, Aussprache, Akzente»

---

### Task 3: Math (`math`, + KaTeX)

**Files:** `package.json` (+ `katex`), `resources/js/lib/math.ts` (render helper), new `resources/js/components/lesson/MathText.vue`, the text-rendering spots in `LessonBlock.vue`, `QuizModule.vue`, `FlashcardModule.vue`, `ClozeModule.vue` (texts only), `LessonView`, `Profile`, `Schemas`, `ContentValidator`, `Progress`, `RecordAnswer`, `SharedLessonController::answer`, new `resources/js/components/lesson/ExerciseModule.vue`, `resources/prompts/profile/math.md`, `Edit.vue`, fixture `dreisatz.json` (+ `dreisatz.graphic.json`), tests.

- KaTeX: TeX inline in texts as `$…$` (and `$$…$$` for display). `MathText` splits a string into text and math parts and renders math with `katex.renderToString(…, { throwOnError: false, strict: 'ignore', output: 'html' })` — never `v-html` on unprocessed text: escape text parts, only KaTeX output is HTML. Import `katex/dist/katex.min.css` in the lesson page bundle only. Use `MathText` only for lessons whose profile is math, geometry or science (prop `math: boolean` from `LessonView`), so a «$» in a history text stays a dollar sign.
- Server-side TeX check (no PHP KaTeX): `ContentValidator` in strict mode for math profiles: balanced `$`, balanced braces, no `\(`/`\[` delimiters; error → repair path.
- Block `worked_solution`: `{ task, steps: [{ text, reason: string|null }], result }` (2–8 steps).
- Module `exercises`: `{ instructions: string|null, entries: [{ id ('a1'…), question, kind: 'number'|'fraction'|'text', answer: string, tolerance: number|null, unit: string|null, hint: string|null, solution_path: string }] }` (3–8). Checking (`Progress::check`, module `exercises`): `number` → parse Swiss/German formats («1'250,5», «1250.5», «1 250»), compare with `tolerance` (default 0), unit optional but if given must match case-insensitively after trimming; `fraction` → «3/4», «0,75» and «6/8» all equal 3/4 (compare as reduced fraction / float with 1e-9); `text` → `ClozeParser::normalize` equality. Unit tests for the parser with many formats.
- `ExerciseModule.vue`: input per task, «Prüfen» → POST answer (existing endpoint, module `exercises`) → right/wrong, then `hint` on first wrong answer, `solution_path` (with MathText) after correct or second wrong answer. In the parent preview (no token) it checks locally? → No: parent view has no answer endpoint today — check how QuizModule handles the parent view (emits `answer` only for the child) and follow the same pattern: correctness for display may be computed client-side for UX, the server stores the authoritative result.
- `math.md`: always one `worked_solution`; formulas in `$…$`; `exercises` instead of `sorting` where it fits; no experiments; graphic pattern `calculator` preferred.
- Progress items and answer endpoint accept `exercises`; `ModuleAnswer` TS type extended.
- Edit.vue: editors for `worked_solution` (steps add/remove) and `exercises` (fields per task).
- Fixture `dreisatz.json` (Mathematik, 1. Sek) + `dreisatz.graphic.json` (pattern `calculator`).
- Tests: number/fraction/unit parsing; endpoint stores attempts for `exercises`; TeX checker; schema per profile; probe OK.

**Commit:** «Fachprofil Mathematik: Formeln, Rechenweg, Aufgaben»

---

### Task 4: Geometry (`geometry`)

**Files:** `Profile` (geometry = math + `figure`), `Schemas`, `ContentValidator`, new `resources/js/components/lesson/FigureBlock.vue`, `LessonBlock.vue`, `resources/prompts/profile/geometry.md`, `Edit.vue` (only remove), fixture `winkel-parallelen.json` (+ `winkel-parallelen.graphic.json`), tests.

- Block `figure`: `{ title: string|null, points: [{ id, x, y, label: string|null }], lines: [{ from, to, label: string|null, style: 'solid'|'dashed' }], angles: [{ vertex, from, to, label: string|null }], areas: [{ points: [id…], category: 'cat1'|'cat2'|'cat3' }] }`; coordinates 0–100 (viewBox), max 12 points / 16 lines / 6 angles / 4 areas. Keep the schema small (it's the largest new block — probe!). If geometry's page schema is too large, drop `areas` first and report.
- Validator: ids unique, every reference exists, coordinates 0–100, at least 2 points.
- `FigureBlock.vue`: renders SVG from the JSON (no model-written SVG): lines, arcs for angles (radius scaled), labels with collision-light placement (offset away from centroid), area fills with the palette's category colours, theme-aware via the lesson CSS variables, `role="img"` + `aria-label` from `title` + labels. Responsive width, max ~420px.
- `geometry.md`: describe figures only via `figure`; constructions step by step via `worked_solution`; interactive constructions via graphics (Teil 2).
- Fixture `winkel-parallelen.json` (Geometrie, 1. Sek).
- Tests: validator (bad reference, out-of-range coordinate), schema, probe OK; Vue rendering verified by build + browser if available.

**Commit:** «Fachprofil Geometrie: Figuren»

---

### Task 5: German (`german`)

**Files:** `Profile`, `Schemas`, `ContentValidator`, `Progress`, `RecordAnswer`, `SharedLessonController::answer`, new `resources/js/components/lesson/MistakeModule.vue`, `ClozeModule.vue`/`ClozeParser` (case-sensitive option), `resources/prompts/profile/german.md`, `Edit.vue`, fixture `das-dass.json`, tests.

- Module `find_the_mistake`: `{ instructions: string|null, entries: [{ id ('f1'…), sentence, mistake_word: int (0-based word index), correction, explanation }] }` (4–8). Words = split on spaces, punctuation stays attached; the UI strips punctuation for matching the correction. Checking: answer `{word: int, correction: string}` → correct when the index matches and the correction equals `correction` exactly (case-sensitive, trimmed, punctuation-insensitive at the end).
- Cloze case sensitivity: `cloze.case_sensitive: bool|null` (only in german's schema; default null = case-insensitive as today). `ClozeParser::check` respects it; ClozeModule's hint text adapts («Gross- und Kleinschreibung zählt.»).
- `german.md`: rule `box` with examples and counterexamples; `find_the_mistake` for rules like das/dass, Kommas, Gross-/Kleinschreibung; cloze with `case_sensitive: true` for spelling topics; no experiments; graphic default `none`.
- Progress items + endpoint accept `find_the_mistake`; TS types.
- Edit.vue: editor for `find_the_mistake` (sentence, word index picker by clicking a word, correction, explanation).
- Fixture `das-dass.json` (Deutsch, 1. Sek).
- Tests: word splitting/punctuation, checking, case-sensitive cloze, schema, probe OK.

**Commit:** «Fachprofil Deutsch: Fehler finden, Gross-/Kleinschreibung»

---

### Task 6: Docs and final checks

- Design doc «Teil 5»: mark implemented, note deviations (TeX check heuristic instead of server KaTeX, schema per profile, probe results per profile with byte sizes).
- Full verification incl. `npm run build` and the probe for all profiles.

**Commit:** «Docs: Teil 5»

---

### Task 7: Real API check (manual, by the user)

One lesson per profile (Französisch vocab page, Dreisatz, Winkel, das/dass). Check: profile detected, blocks used sensibly, speech works, number answers accepted in Swiss format, figure renders, costs per step.
