# Teil 5 – Fachprofile: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Lesson pages fit the subject. Same page layout and building blocks, but per profile a prompt addendum, an allowed set of blocks and modules, and defaults. Profiles: `naturwissenschaften` (today), `allgemein`, `sprachen`, `mathematik`, `geometrie`, `deutsch`.

**Architecture:**
- `App\Lessons\Profile` (enum, string-backed) knows per profile: label, allowed block types, allowed modules, extra prompt file `resources/prompts/profile/{value}.md`, example fixture, defaults (graphics mode, modules), and for `sprachen` the speech language.
- `lessons.profile` (nullable string): null = automatic. The automatic profile is derived from the subject via `config('lessons.profiles')` (case-insensitive map) **after step 1 of the analysis**, because the subject may only be known then (AI detection). The resolved profile is stored on the lesson.
- **Schema size is the main constraint.** The API rejects structured-output grammars that compile too large (see `docs/plans/2026-10-02-einfach-erweitert.md` and the split in commit 54abf90). Therefore `Schemas::page(Profile)` and `Schemas::modules(Profile)` only contain the blocks/modules allowed for that profile. A profile never gets all new blocks at once. After every schema change, run the API probe (see below) for **every** profile.
- `ContentValidator` becomes profile-aware: blocks/modules not allowed for the lesson's profile are errors on fresh generations (strict) and tolerated on display (non-strict), so old content never breaks.
- New answer types (`aufgaben`, `fehler_finden`) are checked on the server in `App\Lessons\Progress::check()`, like today's modules. The browser never decides correctness.

**Tech Stack:** Laravel 13, Pest 4, Inertia 3 + Vue 3 + Tailwind 4. New dependency (approved by the user): `katex` (npm) for formulas in Mathematik/Geometrie/Naturwissenschaften.

**Design:** `docs/plans/2026-10-02-neue-lernseite-design.md`, section «Teil 5».

**Conventions:** as in the earlier plans (German UI/comments/prompts, Swiss spelling, English test names, `composer format` (PHP-CS-Fixer, tabs), full suite, phpstan 0, `npm run types:check`, `npm run check`, commits German with `Co-Authored-By`, stage by name, never commit `public/build`/`package-lock.json` — except `package.json` + the lockfile change for `katex` in Task 3, which must be committed —, never migrate `database/database.sqlite`).

**Fixtures:** each profile task adds its own hand-written fixture (`passe-compose`, `dreisatz`, `winkel-parallelen`, `das-dass`) under `database/fixtures/lessons/`, listed in a new `LessonFactory::PROFILE_FIXTURES` (keep `FIXTURES` = the two science fixtures for existing datasets). Same quality and Swiss spelling as the existing fixtures, `herkunft: "foto"` everywhere. The profile uses its fixture as the example in the page prompt.

**API probe:** `/private/tmp/claude-501/-Users-marceli-to-Jamon-digital-Webroot-tutor-kimiyu-ch/e7dd5486-15dd-473f-918f-d4d584f0eca8/scratchpad/probe.php` sends schemas with `max_tokens: 1` (< $0.01 per run). Extend its candidate list to loop over `Profile::cases()` for `part('seite', $profile)` and `modulesResult($profile)`. Every candidate must print OK before committing a schema change. Also extend the size-guard test in `tests/Unit/Lessons/SchemasTest.php` to every profile.

---

### Task 1: Profile framework (no new blocks yet)

**Files:** `app/Lessons/Profile.php` (new enum); `config/lessons.php` (`profiles` map); migration `2026_10_02_180000_add_profile_to_lessons_table.php`; `app/Models/Lesson.php`; `app/Http/Requests/StoreLessonRequest.php`; `LessonController` (`store`, `create`); `app/Lessons/LessonGenerator.php` (`analyze`); `app/Lessons/Ai/Prompts.php`; `app/Lessons/Ai/Schemas.php`; `app/Lessons/ContentValidator.php`; `resources/prompts/profile/naturwissenschaften.md`, `allgemein.md`; Create.vue (advanced mode: select «Fachprofil»); tests.

- Enum cases: `Naturwissenschaften`, `Allgemein`, `Sprachen`, `Mathematik`, `Geometrie`, `Deutsch`. Methods: `label()`, `blocks(): list<string>` (base: absatz, formel, fakten, spalten, box, grafik), `modules(): list<string>` (base: quiz, sortieren, karten, lueckentext), `promptFile()`, `fixture(): string` (example for the page prompt; `fotosynthese` for naturwissenschaften/allgemein until a profile has its own), `defaultGraphicsMode()`, `allowsExperiments(): bool` (`probieren` only for naturwissenschaften), `speechLang(?string $subject): ?string` (only sprachen; Task 3).
- Config map, e.g. `'biologie' => 'naturwissenschaften', 'chemie' => …, 'physik' => …, 'natur und technik' => …, 'mathematik' => 'mathematik', 'geometrie' => 'geometrie', 'deutsch' => 'deutsch', 'französisch' => 'sprachen', 'englisch' => 'sprachen', 'italienisch' => 'sprachen'`; everything else `allgemein`. `Profile::forSubject(?string)`.
- `lessons.profile` nullable. Request: `profile` `nullable|in:…` (advanced mode only; easy mode sends nothing). `analyze()`: after step 1 (subject known), set `profile = $lesson->profile ?? Profile::forSubject($lesson->subject)`.
- Prompts: `context()` and `pageRequest()` add `Fachprofil: {label}` plus the content of the profile prompt file (`analysis()` step 1 doesn't need it). The graphic step gets the profile label only (graphic patterns differ per subject), not the full addendum. `naturwissenschaften.md` = what's special today (experiments, Alltagsvergleich); `allgemein.md` = no experiments, timelines and maps welcome.
- `module.md`/`analyse.md`: «Halte dich an den Abschnitt «Fachprofil», er geht den allgemeinen Regeln vor.»
- Schemas: `page(?Profile $profile = null)` and `modules(?Profile $profile = null)` filter the block `anyOf` and module properties by the profile (null = today's full set, used for display/validation helpers). `part('seite', $profile)`, `modulesResult($profile)`, repair schemas pass the lesson's profile. Module properties not allowed for a profile are **omitted** from the schema (not nullable), and the generator fills them with null afterwards so stored content keeps the same shape.
- `probieren`: omitted from the page schema when `! allowsExperiments()`; generator sets `probieren = null`.
- Validator: `ContentValidator::errors($content, strict, ?Profile)`; in strict mode report blocks/modules outside the profile.
- Advanced form: select «Fachprofil» (Automatisch + 6 labels) next to Fach.
- Tests: subject → profile mapping (incl. detected subject), explicit profile wins, prompt contains the addendum, graphic prompt only the label, schema per profile omits disallowed parts, generator nulls them, strict validator rejects a disallowed block, probe OK for all profiles.

**Commit:** «Fachprofile: Grundgerüst»

---

### Task 2: Sprachen

**Files:** `Profile` (blocks + `vokabeln`, `konjugation`; speech lang), `Schemas`, `ContentValidator`, `resources/prompts/profile/sprachen.md`, `resources/js/types/lesson.ts`, `LessonBlock.vue`, `FlashcardModule.vue`, `ClozeModule.vue` (+ `ClozeParser` server side), new `resources/js/components/lesson/SpeakButton.vue`, `Edit.vue`, fixture `passe-compose.json`, tests.

- Block `vokabeln`: `{ titel: string|null, eintraege: [{ fremd, deutsch, info: string|null }] }` (info e.g. «m.», «Verb», example sentence). 4–30 entries.
- Block `konjugation`: `{ verb, zeit, formen: [{ person, form }] }` (6 rows).
- `sprachen.md`: vocab tables instead of long explanations; grammar rule in a `box` with 2–3 example sentences; flashcards front = foreign word, back = German (+ short example); cloze for verb forms and vocabulary; no experiments; graphic default `none`.
- Speech: `Profile::speechLang($subject)` → `fr-FR` / `en-GB` / `it-IT`; `LessonView` passes `speechLang` (null otherwise). `SpeakButton` uses `window.speechSynthesis` with that `lang`, hidden when unsupported or no voice for the language; on vocabulary rows (foreign word) and card fronts. No network.
- Flashcards: in a sprachen lesson a toggle «Deutsch zuerst» flips which side shows first (UI only, per page view).
- Cloze accents: `ClozeParser::check(string $answer, array $solutions): 'richtig'|'fast'|'falsch'` — «fast» when it matches after removing diacritics (`Normalizer` + strip combining marks) but not exactly (case-insensitive as today). `isCorrect()` stays (true only for «richtig»). The answer endpoint returns `{correct, almost}`; ClozeModule shows «Fast – achte auf den Akzent: {Musterlösung}». Progress counts «fast» as not correct.
- Edit.vue: table editors for `vokabeln` (add/remove rows) and `konjugation` (6 fixed rows).
- Fixture `passe-compose.json` (Französisch, 2. Sek): vokabeln + konjugation + box + karten + lueckentext + quiz.
- Tests: schema for sprachen contains the two blocks, others don't; validator rules; `fast` detection (é/e, à/a, ü/u, case); answer endpoint `almost`; `speechLang` in shared view props for Französisch only; fixture validates strictly with profile sprachen; probe OK.

**Commit:** «Fachprofil Sprachen: Vokabeln, Konjugation, Aussprache, Akzente»

---

### Task 3: Mathematik (+ KaTeX)

**Files:** `package.json` (+ `katex`), `resources/js/lib/math.ts` (render helper), new `resources/js/components/lesson/MathText.vue`, the text-rendering spots in `LessonBlock.vue`, `QuizModule.vue`, `FlashcardModule.vue`, `ClozeModule.vue` (texts only), `Profile`, `Schemas`, `ContentValidator`, `Progress`, `SharedLessonController::answer`, new `resources/js/components/lesson/TaskModule.vue`, `resources/prompts/profile/mathematik.md`, `Edit.vue`, fixture `dreisatz.json` (+ hero), tests.

- KaTeX: TeX inline in texts as `$…$` (and `$$…$$` for display). `MathText` splits a string into text and math parts and renders math with `katex.renderToString(…, { throwOnError: false, strict: 'ignore', output: 'html' })` — never `v-html` on unprocessed text: escape text parts, only KaTeX output is HTML. Import `katex/dist/katex.min.css` in the lesson page bundle only. Use `MathText` only for lessons whose profile is mathematik, geometrie or naturwissenschaften (prop `math: boolean` from `LessonView`), so a «$» in a history text stays a dollar sign.
- Server-side TeX check (no PHP KaTeX): `ContentValidator` in strict mode for math profiles: balanced `$`, balanced braces, no `\(`/`\[` delimiters; error → repair path.
- Block `rechenweg`: `{ aufgabe, schritte: [{ text, begruendung: string|null }], resultat }` (2–8 steps).
- Module `aufgaben`: `{ anleitung: string|null, eintraege: [{ id ('a1'…), frage, art: 'zahl'|'bruch'|'text', loesung: string, toleranz: number|null, einheit: string|null, tipp: string|null, loesungsweg: string }] }` (3–8). Checking (`Progress::check`, module `aufgaben`): `zahl` → parse Swiss/German formats («1'250,5», «1250.5», «1 250»), compare with `toleranz` (default 0), unit optional but if given must match case-insensitively after trimming; `bruch` → «3/4», «0,75» and «6/8» all equal 3/4 (compare as reduced fraction / float with 1e-9); `text` → `ClozeParser::normalize` equality. Unit tests for the parser with many formats.
- `TaskModule.vue`: input per task, «Prüfen» → POST answer (existing endpoint, module `aufgaben`) → right/wrong, then tip on first wrong answer, `loesungsweg` (with MathText) after correct or second wrong answer. In the parent preview (no token) it checks locally? → No: parent view has no answer endpoint today — check how QuizModule handles the parent view (emits `answer` only for the child) and follow the same pattern: correctness for display may be computed client-side for UX, the server stores the authoritative result.
- `mathematik.md`: always one `rechenweg`; formulas in `$…$`; `aufgaben` instead of `sortieren` where it fits; no experiments; graphic pattern `rechner` preferred.
- Progress items and answer endpoint accept `aufgaben`; `ModuleAnswer` TS type extended.
- Edit.vue: editors for `rechenweg` (steps add/remove) and `aufgaben` (fields per task).
- Fixture `dreisatz.json` (Mathematik, 1. Sek) + `dreisatz.hero.json` (rechner).
- Tests: number/fraction/unit parsing; endpoint stores attempts for `aufgaben`; TeX checker; schema per profile; probe OK.

**Commit:** «Fachprofil Mathematik: Formeln, Rechenweg, Aufgaben»

---

### Task 4: Geometrie

**Files:** `Profile` (geometrie = mathematik + `figur`), `Schemas`, `ContentValidator`, new `resources/js/components/lesson/FigureBlock.vue`, `LessonBlock.vue`, `resources/prompts/profile/geometrie.md`, `Edit.vue` (only remove), fixture `winkel-parallelen.json` (+ hero), tests.

- Block `figur`: `{ titel: string|null, punkte: [{ id, x, y, label: string|null }], linien: [{ von, bis, label: string|null, stil: 'voll'|'gestrichelt' }], winkel: [{ scheitel, von, bis, label: string|null }], flaechen: [{ punkte: [id…], kategorie: 'cat1'|'cat2'|'cat3' }] }`; coordinates 0–100 (viewBox), max 12 points / 16 lines / 6 angles / 4 areas. Keep the schema small (it's the largest new block — probe!). If geometrie's page schema is too large, drop `flaechen` first and report.
- Validator: ids unique, every reference exists, coordinates 0–100, at least 2 points.
- `FigureBlock.vue`: renders SVG from the JSON (no model-written SVG): lines, arcs for angles (radius scaled), labels with collision-light placement (offset away from centroid), area fills with the palette's category colours, theme-aware via the lesson CSS variables, `role="img"` + `aria-label` from `titel` + labels. Responsive width, max ~420px.
- `geometrie.md`: describe figures only via `figur`; constructions step by step via `rechenweg`; interactive constructions via graphics (Teil 2).
- Fixture `winkel-parallelen.json` (Geometrie, 1. Sek).
- Tests: validator (bad reference, out-of-range coordinate), schema, probe OK; Vue rendering verified by build + browser if available.

**Commit:** «Fachprofil Geometrie: Figuren»

---

### Task 5: Deutsch

**Files:** `Profile`, `Schemas`, `ContentValidator`, `Progress`, `SharedLessonController::answer`, new `resources/js/components/lesson/MistakeModule.vue`, `ClozeModule.vue`/`ClozeParser` (case-sensitive option), `resources/prompts/profile/deutsch.md`, `Edit.vue`, fixture `das-dass.json`, tests.

- Module `fehler_finden`: `{ anleitung: string|null, eintraege: [{ id ('f1'…), satz, fehler_wort: int (0-based word index), korrektur, erklaerung }] }` (4–8). Words = split on spaces, punctuation stays attached; the UI strips punctuation for matching the correction. Checking: answer `{wort: int, korrektur: string}` → correct when the index matches and the correction equals `korrektur` exactly (case-sensitive, trimmed, punctuation-insensitive at the end).
- Cloze case sensitivity: `lueckentext.gross_klein: bool|null` (only in deutsch's schema; default null = case-insensitive as today). `ClozeParser::check` respects it; ClozeModule's hint text adapts («Gross- und Kleinschreibung zählt.»).
- `deutsch.md`: rule `box` with examples and counterexamples; `fehler_finden` for rules like das/dass, Kommas, Gross-/Kleinschreibung; cloze with `gross_klein: true` for spelling topics; no experiments; graphic default `none`.
- Progress items + endpoint accept `fehler_finden`; TS types.
- Edit.vue: editor for `fehler_finden` (sentence, word index picker by clicking a word, correction, explanation).
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
