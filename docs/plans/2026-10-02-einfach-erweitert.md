# Formular «Einfach | Erweitert»: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The «Neue Lernseite» form gets an easy mode (default) with only photos, Auftrag, child and one of three presets. Subject is detected by the AI, level comes from the child. The advanced mode shows every option openly (no collapsed «Mehr Optionen»).

**Decisions (user, 2026-10-02):** AI detects the subject; presets «Kurz & schnell» / «Normal» / «Prüfung».

**Presets** (frontend constants, map onto existing fields):

| Preset | purpose | scope | graphics_mode | modules |
|---|---|---|---|---|
| Kurz & schnell | neu | kurz | none | quiz, karten |
| Normal | neu | normal | auto | all four |
| Prüfung | pruefung | normal | auto | all four |

A fourth card «Wie letztes Mal» appears when the child's most recent lesson (any subject) doesn't match a preset; it applies those values (custom graphics → `auto`, as today).

**Architecture:** backend changes are small: `subject` and `level` become optional in the request; `lessons.subject` nullable; the analysis returns `fach`, stored when the lesson has no subject. Everything else is frontend.

**Conventions:** as in the earlier plans (German UI/comments/prompts, Swiss spelling, English test names, pint, full suite, phpstan 0, `npm run types:check`, `npm run check`, commits German with `Co-Authored-By`, stage by name, never commit `public/build`/`package-lock.json`, never migrate `database/database.sqlite`). After changing any AI schema, re-run the API probe `/private/tmp/claude-501/-Users-marceli-to-Jamon-digital-Webroot-tutor-kimiyu-ch/e7dd5486-15dd-473f-918f-d4d584f0eca8/scratchpad/probe.php` (max_tokens 1, < $0.01); all schemas must print OK.

---

### Task 1: Subject detected by the AI, level from the child

**Files:** migration `2026_10_02_170000_make_subject_nullable_on_lessons_table.php`; `app/Models/Lesson.php` (docblock); `app/Http/Requests/StoreLessonRequest.php`; `LessonController::store`; `app/Lessons/Ai/Schemas.php` (`analysis`: add `fach`); `app/Lessons/Ai/Prompts.php` (`sourceLines` or wherever «Fach:» is written); `resources/prompts/analyse.md`; `app/Lessons/LessonGenerator.php` (`analyze`); `app/Lessons/Ai/FakeLanguageModel.php`; tests.

- `lessons.subject` → nullable (SQLite table rebuild: verify on a scratch copy of the DB that row counts of lessons/lesson_graphics/generations/attempts stay equal and soft-deleted rows stay deleted).
- Request: `subject` `nullable|string|max:60`; `level` `nullable|string|max:60`, but required when neither the chosen child nor a new child would have a level: if `child_id` is set and that child has a level → optional; else required («Gib die Stufe an.»). Store: `level` = input ?? child's level; `subject` = trimmed input or null. A new child (first lesson) still gets `level` from the input.
- Analysis schema: `'fach' => ['type' => 'string', 'description' => 'Schulfach, z. B. Biologie, Mathematik, Französisch']` (keep it short; re-run the probe).
- Prompt: when `subject` is null, the «Fach:» line reads `Fach: unbekannt, erkenne es aus den Fotos oder dem Auftrag`; analyse.md: «`fach`: das Schulfach nach Lehrplan 21 (z. B. «Natur und Technik», «Mathematik», «Französisch»). Ist ein Fach angegeben, übernimm es unverändert.» The subject list from `Create.vue` can be listed in analyse.md as the preferred names.
- `analyze()`: if `$lesson->subject` is null, store `fach` (trimmed, max 60, fallback «Allgemein» if empty) right after call 1, before call 2 (so the page call and all later prompts have a subject).
- Places that read `subject` before the analysis must cope with null: dashboard grouping, Kosten page, `lastSettings` in `create()`, `SharedLessonController` (only published lessons, which always have a subject after analysis — still guard), `GenerationStatus`/Show title. Use «Fach wird erkannt …» where a label is shown for a generating lesson without subject.
- Tests: upload without subject/level for a child with level → stored subject null, level from child; without level for a child without level → error; analysis prompt says «erkenne»; `fach` stored when subject null; given subject is not overwritten; dashboard/Kosten render with a null-subject lesson.

**Commit:** «Fach erkennt die KI, Stufe kommt vom Kind»

---

### Task 2: `lastSettings` per child

**Files:** `LessonController::create`, tests.

- Besides the existing per child+subject map, pass `lastByChild`: child id → `{purpose, scope, modules, graphics_mode}` of that child's most recent non-deleted lesson (custom graphics → `auto`). One query is enough (reuse the existing one).
- Tests: latest lesson per child wins; deleted lessons ignored; other parents ignored.

**Commit:** «Letzte Einstellungen pro Kind»

---

### Task 3: Easy and advanced mode in the form

**Files:** `resources/js/pages/lessons/Create.vue`; new `resources/js/components/PresetPicker.vue`; `resources/js/components/LessonOptions.vue` / `GraphicsField.vue` (only if layout needs it).

- Mode state `'einfach' | 'erweitert'`, default `einfach`, remembered in `localStorage` (`lernseite.formMode`, every access in try/catch; page must work without storage). Toggle as a text button next to the submit button: «Alle Einstellungen» / «Weniger Einstellungen». Validation errors on fields only shown in advanced mode switch to advanced automatically.
- **Easy mode** shows: `PhotoPicker`, Auftrag (with chips), «Für wen?» (or the first-child name field), the level field only when the selected child has no level (or there are no children yet), `PresetPicker`, submit.
- **Advanced mode** shows everything openly in this order: photos, Auftrag, child, Fach + Stufe (Fach optional, placeholder «leer lassen: die KI erkennt das Fach»), Grafiken (`GraphicsField`), Zweck, Umfang, Lernmodule (`LessonOptions`). Remove the `<details>` «Mehr Optionen» and its summary line.
- `PresetPicker`: radio cards «Kurz & schnell» («Wenig Text, kurzes Quiz und Karteikarten, ohne Grafik. Am günstigsten.»), «Normal» («Erklärung, Quiz, passende Übungen, eine Grafik, wenn sie hilft.»), «Prüfung» («Kompakt, Fokus auf Begriffe und typische Prüfungsfragen.»), plus «Wie letztes Mal» when `lastByChild[childId]` doesn't equal a preset (one-line summary like «Ausführlich · 2 Module · ohne Grafik»). Selecting a card writes purpose/scope/graphics_mode/modules into the form (`graphics` emptied).
- Initial selection: if `lastByChild` for the preselected child matches a preset → that preset; if it exists but matches none → «Wie letztes Mal»; else «Normal». On child change: same rule, unless the parent already picked a card by hand in this visit.
- Switching easy → advanced keeps the current values. Advanced → easy: if the values match a preset or «Wie letztes Mal», select that card; otherwise show a fifth, read-only card «Eigene Einstellungen» (selected) so nothing is silently overwritten.
- The existing per child+subject prefill (`lastSettings`) stays active in advanced mode only (in easy mode the subject is usually empty).
- Verify: types, check, build. Browser check if the Chrome extension is connected (don't submit).

**Commit:** «Formular: Einfach und Erweitert, Voreinstellungen»

---

### Task 4: Docs

- Design doc: new short section «Formular Einfach | Erweitert» (decisions, presets table, subject detection).
- Full verification incl. `npm run build` and the API probe.

**Commit:** «Docs: Formular Einfach | Erweitert»
