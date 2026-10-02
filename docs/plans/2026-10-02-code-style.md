# Code style: English keys, English comments, tabs – Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The code base uses English for identifiers, array/JSON keys and enum values (including the stored lesson content and the Claude structured-output schemas) and for code comments, and indents with tabs everywhere. User-facing text stays German: UI strings, validation messages, the prose of the AI prompts, URLs (`/lernseiten/neu`, `/kinder`, `/k/{token}`).

**Decisions (user, 2026-10-02):** «All English, migrate data», «English comments», «Tabs everywhere».

**Order:** Task 1–3 rename keys/values (one coherent change, data migration included), Task 4 renames remaining German identifiers, Task 5 translates comments, Task 6 switches to tabs (formatting-only commit, last, so earlier diffs stay readable). The suite must be green after every task.

**Conventions:** as in the earlier plans, but from now on: English comments, English keys. German stays in UI text, validation messages and prompt prose. Commits German with `Co-Authored-By`, stage by name, never commit `public/build`/`package-lock.json`, never migrate `database/database.sqlite`. Every schema change → API probe `/private/tmp/claude-501/-Users-marceli-to-Jamon-digital-Webroot-tutor-kimiyu-ch/e7dd5486-15dd-473f-918f-d4d584f0eca8/scratchpad/probe.php` (max_tokens 1, < $0.01), all OK.

---

## Mapping

### Content and AI schema keys

| German | English | German | English |
|---|---|---|---|
| quelle | source | lesbar | readable |
| fach | subject | zusammenfassung | summary |
| ergaenzungen | additions | grafik_plaene | graphic_plans |
| nr | number | hinweis | note |
| muster | pattern | idee | idea |
| seite | page | titel | title |
| anleitung | instructions | thema | topic |
| kernidee | key_idea | abschnitte | sections |
| bloecke | blocks | typ | type |
| zusatz | addendum | eintraege | entries |
| absaetze | paragraphs | kategorie | category |
| kategorien | categories | probieren | try_it |
| experimente | experiments | alltagsvergleich | everyday_comparison |
| nachdenken | reflect | frage | question |
| module | modules | sortieren | sorting |
| karten | flashcards | lueckentext | cloze |
| optionen | options | loesung | answer |
| loesungen | answers | tipp | hint |
| erklaerung | explanation | herkunft | origin |
| begriffe | terms | vorne | front |
| hinten | back | segmente | segments |
| korrekturen | corrections | pfad | path |
| wert | value | bereich | area |
| aenderung | change | beschreibung | description |

Unchanged: `problem`, `plan`, `meta`, `emoji`, `palette`, `text`, `label`, `sub`, `id`, `css`, `markup`, `script`, `box` (block type). Item IDs (`q1`, `s1`, `k1`, `g1`) stay — attempts reference them.

### Values

| Kind | German → English |
|---|---|
| Block `type` | absatz→paragraph, formel→formula, fakten→facts, spalten→columns, box→box, grafik→graphic |
| `origin` | foto→photo, ergaenzt→added |
| Graphic pattern | regler→sliders, ansichten→views, schritte→steps, zeitstrahl→timeline, hotspots→hotspots, rechner→calculator |
| Palette | petrol→petrol, gruen→green, blau→blue, erde→earth, violett→violet, rot→red, anthrazit→anthracite |
| `purpose` | neu→new, pruefung→exam |
| `scope` | kurz→short, normal→normal, ausfuehrlich→detailed |
| Modules | quiz, sortieren→sorting, karten→flashcards, lueckentext→cloze |
| Steps (`lessons.step`, `generations.step`, model config keys, `ModelRequest::$step`) | warteschlange→queued, analyse→analysis, seite→page, module→modules, pruefung→check, grafik→graphic, grafik-{n}→graphic-{n}, grafik-reparatur→graphic-repair, reparatur-seite→repair-page, reparatur-module→repair-modules, neu-quiz→regenerate-quiz, neu-grafik→regenerate-graphic |

Labels shown to people (pattern labels, palette labels, purpose/scope cards) stay German.

### Stored data to migrate

- `lessons.content` (recursive key + value mapping), `lessons.check_notes` (`bereich/aenderung` → `area/change`), `lessons.purpose`, `lessons.scope`, `lessons.modules`, `lessons.step`.
- `lesson_graphics.pattern`, `.plan` (`muster/idee`), `.graphic` (`muster/beschreibung`).
- `attempts.module`.
- `generations.step` (cost history; keep it readable on the Kosten page).
- Old prompt/upload input keys (`graphics[].beschreibung/muster`) → `graphics[].description/pattern`.

### Env and config

`LESSON_MODEL_ANALYSE`→`LESSON_MODEL_ANALYSIS`, `LESSON_MODEL_PRUEFUNG`→`LESSON_MODEL_CHECK`, `LESSON_MODEL_GRAFIK`→`LESSON_MODEL_GRAPHIC`, `LESSON_EFFORT_*` likewise; `LESSON_MODEL_MODULE`→`LESSON_MODEL_MODULES`. Update `.env.example`, `docs/deployment.md`. (Check the local `.env` — if it sets any of these, list them in the report; don't edit `.env`.)

---

### Task 1: Mapping class and data migration

**Files:** `app/Lessons/LegacyKeys.php` (new; the German→English maps and `contentToEnglish(array): array`, `contentToGerman(array): array` for `down()`); migration `2026_10_02_190000_translate_lesson_data_to_english.php`; `tests/Unit/Lessons/LegacyKeysTest.php`.

- Recursive mapping: rename keys by the table; map values only in their fields (`type` of blocks, `origin`, `palette`, `pattern`, `purpose`, `scope`, module names, step names). Never touch free text.
- Round-trip test: both German fixtures (current files) → English → German equals the original; English result contains none of the German keys (walk all keys).
- Migration: chunked updates per table as listed; `down()` maps back. Test on a scratch copy of the real DB (`cp database/database.sqlite <scratchpad>/style-test.sqlite`, `DB_DATABASE=… php artisan migrate --force`): counts unchanged, a sample lesson's content has English keys, rollback restores byte-identical JSON (compare md5 of `content` per row before/after rollback).
- This task only adds the class + migration; the app still reads German keys until Task 2, so **commit Tasks 1–3 together** if the suite can't stay green otherwise (state which you did).

### Task 2: Backend uses English keys

**Files:** everything under `app/` that reads/writes content, schemas, prompts, validator, corrections, progress, views, controllers, requests, jobs, config; `resources/prompts/*.md` (rename files: `analyse.md`→`analysis.md`, `module.md`→`modules.md`, `pruefung.md`→`check.md`, `reparatur.md`→`repair.md`, `grafik.md`→`graphic.md`, `grafik-reparatur.md`→`graphic-repair.md`; update backticked field names inside the German prose to the English keys); `database/fixtures/lessons/*.json` (convert with `LegacyKeys`, keep the hand formatting style as far as practical); `database/factories`; `config/lessons.php`; `.env.example`; `docs/deployment.md`; all PHP tests.

- Validation messages and UI text stay German; attribute names in messages that leak key names (e.g. «Das Feld module.quiz …») should get German `attributes()` where they reach parents.
- Prompts: the model must produce English keys but German content. Add one line to each system prompt: «Die Feldnamen sind englisch, alle Inhalte schreibst du auf Deutsch (Schweizer Rechtschreibung).»
- API probe after the schema change: all OK; size-guard test still green.

### Task 3: Frontend uses English keys

**Files:** `resources/js/types/lesson.ts`, all components under `resources/js/components/lesson/`, `pages/lessons/*.vue`, `pages/shared/*.vue`, `GraphicsField.vue`, `LessonOptions.vue`, `PresetPicker.vue`, `lib/presets.ts`, `lib/lesson.ts`; regenerate Wayfinder (`php artisan wayfinder:generate --with-form`).
- `npm run types:check`, `npm run check`, `npm run build`.
- Commit (with Tasks 1–2 if combined): «Englische Schlüssel im Inhalt, in Schemas und Code; Daten migriert»

### Task 4: Remaining German identifiers

- Classes/files: `HeroPattern`→`GraphicPattern`, `HeroValidator`→`GraphicValidator`, `HeroDocument`→`GraphicDocument`, `HeroFrame.vue`→`GraphicFrame.vue`, `resources/lesson/hero-base.css`→`graphic-base.css`, `resources/views/lesson-hero.blade.php`→`lesson-graphic.blade.php`; methods `Prompts::hero/heroRepair/heroPlanText` → `graphic/graphicRepair/graphicPlansText`; drop the duplicate `hero` prop in `LessonView::page()` (frontend uses `graphics[1]`). postMessage source `lernseite-hero` → `lesson-graphic` (both sides).
- German variable/method names anywhere (`grep -rnE "\\$(inhalt|seite|antwort|fehler|bild|kind)\\b|function [a-z]*(Seite|Grafik|Kind)" app resources/js tests`, plus a manual pass).
- Commit: «Englische Namen für Klassen, Methoden und Variablen»

### Task 5: English comments

- Translate every code comment and docblock in `app/`, `config/`, `database/`, `routes/`, `tests/`, `resources/js/`, `resources/css/`, `resources/views/` to English. Keep meaning; don't add new comments. Test names are already English.
- Not comments: UI strings, validation messages, prompt files, German test data.
- Commit: «Kommentare auf Englisch»

### Task 6: Tabs everywhere

- `.editorconfig`: `indent_style = tab` (keep `indent_size = 4` for display; YAML/JSON fixtures? → YAML must stay spaces; `package.json`/`composer.json` can stay spaces — decide and document in `.editorconfig` sections).
- Frontend: `vite.config.ts` `fmt.useTabs: true`; run `npm run check:fix`.
- PHP: Pint has no tab option. Replace with PHP-CS-Fixer (`friendsofphp/php-cs-fixer` dev dependency) and a `.php-cs-fixer.dist.php` that reproduces the Laravel preset as closely as practical (start from `@PER-CS2.0` + the rules Pint's `laravel` preset sets; there are published Laravel rule sets you can copy into the config — no new package beyond php-cs-fixer) with `->setIndent("\t")`. Remove `pint.json` and `laravel/pint` from `composer.json` (dev), add `composer format` script; update `.github/workflows/*` if they run Pint; update `docs/` and the plans' «Conventions» lines that say `vendor/bin/pint --dirty` → `composer format` (only in `docs/plans/2026-10-02-teil-5-fachprofile.md` and later; old plans are history).
- Blade and CSS files not covered by a formatter: convert leading 4-space groups to tabs with a script.
- Run the formatters over the whole repo, commit **only** formatting: «Einrückung mit Tabs». Then verify nothing but whitespace changed: `git diff -w HEAD~1 --stat` must be empty (except config files of this task, committed separately first: «Formatierung: PHP-CS-Fixer und Tabs»).
- Full verification after: tests, phpstan, types, check, build.

### Task 7: Update the Part 5 plan

- `docs/plans/2026-10-02-teil-5-fachprofile.md`: replace German keys/values in the new blocks and modules with English ones (e.g. `vokabeln`→`vocabulary`, `konjugation`→`conjugation`, `rechenweg`→`worked_solution`, `aufgaben`→`exercises`, `figur`→`figure`, `fehler_finden`→`find_the_mistake`, fields like `fremd/deutsch/info`→`foreign/german/info`, `verb/zeit/formen/person/form`→`verb/tense/forms/person/form`, `schritte/begruendung/resultat`→`steps/reason/result`, `art/loesung/toleranz/einheit/loesungsweg`→`kind/answer/tolerance/unit/solution_path`, `punkte/linien/winkel/flaechen/scheitel/von/bis/stil`→`points/lines/angles/areas/vertex/from/to/style`, `satz/fehler_wort/korrektur/erklaerung`→`sentence/mistake_word/correction/explanation`, `gross_klein`→`case_sensitive`); profile values `naturwissenschaften/allgemein/sprachen/mathematik/geometrie/deutsch` → `science/general/languages/math/geometry/german`; prompt profile files accordingly. Conventions line: English comments, tabs, `composer format`.
- Commit: «Plan Teil 5: englische Schlüssel»
