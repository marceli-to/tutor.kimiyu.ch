# Teil 2 – Grafiken selbst bestimmen: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Parents choose «Keine», «KI entscheidet» (one graphic, today's behaviour) or «Selbst beschreiben» (up to 3 graphics, each with a description and optional pattern). Graphic 1 is the hero at the top; graphics 2–3 appear inside the section they illustrate. Each graphic is generated, repaired and regenerated on its own.

**Architecture:**
- New table `lesson_graphics` (one row per graphic, `position` 1–3) replaces `lessons.hero`, `hero_plan`, `hero_error`. `lessons.with_hero` becomes `lessons.graphics_mode` (`none` | `auto` | `custom`). Existing data is migrated to position 1, then the old columns are dropped (last task).
- In `custom` mode the wishes are stored as rows at upload time (`request`, `pattern`). The analysis returns `grafik_plaene[]` (`nr`, `plan` or null, `hinweis`) and places graphics 2–3 as a new content block `{ "typ": "grafik", "nr": 2 }`.
- The pipeline queues one `GenerateLessonGraphic(lesson, position)` job per possible position (1 for `auto`, 1–3 for `custom`); a job without a plan for its position does nothing. One failing graphic never affects the others (each has its own `error`).
- Each graphic has its own signed iframe URL `lernseiten/{lesson}/grafik/{nr}` and its own regenerate route `lernseiten/{lesson}/grafik/{nr}/neu`.

**Tech Stack:** Laravel 13, Pest 4, Inertia 3 + Vue 3 + Tailwind 4.

**Design:** `docs/plans/2026-10-02-neue-lernseite-design.md`, section «Teil 2».

**Conventions:** as in `docs/plans/2026-10-02-teil-1-auftrag.md` (German comments/UI/prompts, Swiss spelling, English test names, pint, full suite, phpstan, `npm run types:check`, `npm run check`, commits German with `Co-Authored-By`, stage files by name, never commit `public/build`/`package-lock.json`, never migrate `database/database.sqlite`).

**Privacy reminder:** graphics are shown to the child. Never send `herkunft`, the «Ergänzt» list or other parent-only data to the graphic step (see `Prompts::hero()` today, `context($lesson, forChild: true)`).

---

### Task 1: Table, model, migration of existing graphics

**Files:** migration `2026_10_02_140000_create_lesson_graphics_table.php`; `app/Models/LessonGraphic.php`; `app/Models/Lesson.php` (relation, `graphics_mode`); `database/factories/LessonFactory.php` (+ `LessonGraphicFactory` if useful); tests.

- `lesson_graphics`: `id`, `lesson_id` (constrained, cascadeOnDelete), `position` (unsignedTinyInteger), `request` (text, nullable – wish of the parents), `pattern` (string nullable – wished `HeroPattern` value), `plan` (json nullable – `{muster, idee}`), `graphic` (json nullable – `{muster, beschreibung, css, markup, script}`), `error` (text nullable), timestamps; unique (`lesson_id`, `position`).
- `lessons.graphics_mode` (string, default `auto`), backfilled: `with_hero = false` → `none`, else `auto`.
- Backfill: for every lesson with `hero_plan` or `hero` or `hero_error` not null, insert position 1 with `plan = hero_plan`, `graphic = hero`, `error = hero_error`. Old columns stay for now (dropped in Task 8), so the app keeps working between tasks.
- `Lesson::graphics()` HasMany ordered by `position`; `graphic(int $position): ?LessonGraphic`. `LessonGraphic` casts `plan`/`graphic` as array, `lesson()` BelongsTo (withTrashed).
- Factory `fromFixture()` also creates graphic 1 from `{fixture}.hero.json` (plan from its `muster`/`beschreibung`).
- `LessonController::destroy()`: also delete the lesson's graphics (content of graphics is lesson content; see the existing emptying logic).
- Tests: migration backfill (create a lesson with old columns, run the backfill logic — extract it into a small static method on the migration or a class so it is testable, or test via `artisan migrate` on the in-memory DB with seeded rows before the migration — pick the simplest that really tests it), relation order, delete removes graphics.

**Commit:** «Grafiken in eigener Tabelle, bestehende übernommen»

---

### Task 2: Upload with graphics mode and wishes

**Files:** `app/Http/Requests/StoreLessonRequest.php`, `LessonController::store`, tests.

- Rules: `graphics_mode` required, in `none,auto,custom`; `graphics` `exclude_unless:graphics_mode,custom`, required, array, min 1, max 3; `graphics.*.beschreibung` required string max 500; `graphics.*.muster` nullable, in `HeroPattern` values. Remove `with_hero`. German messages («Beschreib mindestens eine Grafik.», «Höchstens 3 Grafiken.», «Beschreib, was die Grafik zeigen soll.»).
- Store: `graphics_mode`; in `custom` create rows position 1..n with `request`/`pattern`.
- Tests: each mode; custom with 0 and 4 wishes rejected; pattern validated; wishes stored in order.

**Commit:** «Upload: Grafik-Modus und Wünsche»

---

### Task 3: Analysis plans the graphics

**Files:** `app/Lessons/Ai/Schemas.php`, `app/Lessons/ContentValidator.php`, `resources/js/types/lesson.ts`, `app/Lessons/Ai/Prompts.php` (`analysis`, `modules` via `heroPlanText`), `app/Lessons/LessonGenerator.php` (`analyze`), `app/Lessons/Ai/FakeLanguageModel.php`, `resources/prompts/analyse.md`, `module.md`, tests.

- Analysis schema: replace `hero_plan` with `grafik_plaene`: array of `{ nr: integer, plan: nullable heroPlan(), hinweis: nullable string }`.
- New block type `grafik` in `Schemas::page()` (`$block('grafik', ['nr' => ['type' => 'integer']])`, with `herkunft` like all blocks) and `ContentValidator::BLOCK_TYPES`; rule `abschnitte.*.bloecke.*.nr` → `required_if:…typ,grafik|integer|min:2|max:3`. A graphic block must not be the only block of a section in strict mode (it needs explaining text) — add to `checkConsistency` if simple, else skip and note.
- TS: `{ typ: 'grafik'; nr: number }` in `LessonBlock`.
- Prompt input (`Prompts::analysis`): replace the «Interaktive Grafik: …» line by
  - `none`: `Grafiken: keine (von den Eltern abgewählt)`
  - `auto`: `Grafiken: höchstens eine, wenn ein Muster den Stoff sichtbar macht`
  - `custom`: `Grafiken nach Wunsch der Eltern:` + one line per row `Grafik {n}: {request}` + ` (Muster: {label})` if a pattern is set.
- analyse.md: rewrite section «Hauptgrafik» → «Grafiken»: rules per mode; graphic 1 is the hero under the title, 2–3 go into a section via the `grafik` block next to the explanation they show; in `custom` every wish gets an entry in `grafik_plaene` with the same `nr`; if a wish doesn't fit the material (frame of the photos!), `plan: null` and `hinweis` with one sentence for the parents; never invent content for a graphic; wished pattern is binding unless it can't show the content (then explain in `hinweis` and pick another). Keep the pattern table. `probieren` refers to graphic 1.
- `LessonGenerator::analyze()`: write plans into `lesson_graphics` (update or create position `nr`; `auto` creates position 1 only; ignore `nr` outside 1..3 and, in custom mode, numbers without a wish; set `error = hinweis` when `plan` is null). Remove writing `hero_plan`.
- `heroPlanText()` → lists all planned graphics («Grafik 1 (oben): Muster …», «Grafik 2 (im Abschnitt …)»), or the existing «keine Grafik» sentence.
- Fake: `grafik_plaene: [{nr: 1, plan: {...}, hinweis: null}]`.
- Tests: each mode's prompt line; plans stored per position; `plan: null` → error stored; block `grafik` validates, `nr: 4` rejected; module prompt lists all plans.

**Commit:** «Analyse plant bis zu drei Grafiken»

---

### Task 4: Generate each graphic separately

**Files:** `app/Jobs/GenerateLessonGraphic.php` (replaces `GenerateLessonHero`), `app/Jobs/RegenerateHero.php` → `RegenerateGraphic.php`, `app/Lessons/GenerationPipeline.php`, `app/Lessons/LessonGenerator.php` (`hero` → `graphic(Lesson, int $position, bool $keepExisting = false)`), `app/Lessons/Ai/Prompts.php` (`hero`, `heroRepair` take the `LessonGraphic`), `resources/prompts/grafik.md`, tests.

- Job `GenerateLessonGraphic(Lesson $lesson, int $position)`: step name `grafik-{position}` (status display); calls `$generator->graphic($lesson, $position)`. Model config: step names for the API stay `grafik` / `grafik-reparatur` (so `config('lessons.models.grafik')` applies).
- Pipeline `start()`: after analysis/check, queue `GenerateLessonGraphic` for positions 1 (`auto`) or 1..3 (`custom`), only for positions without a finished `graphic`; nothing for `none`.
- `graphic()`: like today's `hero()` but per row: no plan → nothing; success → `graphic` saved, `error` null; failure → `error` (keep old graphic when regenerating). Never touches other rows.
- `Prompts::hero(Lesson, LessonGraphic)`: plan of that graphic; for position ≥ 2 add «Diese Grafik steht im Abschnitt «{titel}» neben dem Text, nicht oben auf der Seite.» (find the section via the `grafik` block with that `nr`; fallback «weiter unten auf der Seite»). grafik.md: generalise «Hauptgrafik … direkt unter dem Titel» to cover graphic 1 vs. 2–3.
- Regenerate: `canRegenerate($lesson, 'grafik', $position)`; route `POST lernseiten/{lesson}/grafik/{nr}/neu` (`whereNumber`, 1–3) → `RegenerateGraphic($lesson, $position)` + `FinishLesson`. Remove `grafik` from the old `regenerate/{part}` route (keep `quiz`).
- Tests: custom with 3 plans → 3 `grafik` requests, one broken (repair also broken) → that one has `error`, others fine, lesson in Review; retry only regenerates missing graphics; regenerate graphic 2 keeps 1 and 3; auto behaves like today; none → no graphic request.

**Commit:** «Jede Grafik einzeln erstellen und neu erstellen»

---

### Task 5: Serve and show the graphics

**Files:** `routes/web.php`, `LessonController` (`hero` → `graphic(Lesson, int $nr)`), `app/Lessons/HeroDocument.php` (takes the graphic array), `app/Lessons/LessonView.php`, `LessonController::render`, `resources/js/types/lesson.ts`, `resources/js/components/lesson/LessonPage.vue`, `LessonBlock.vue`, `resources/js/pages/lessons/Show.vue`, `resources/js/pages/shared/Show.vue`, `ReviewNotice.vue`, `ParentToolbar.vue`, `GenerationStatus.vue`, tests.

- Signed route `GET lernseiten/{lesson}/grafik/{nr}` (`whereNumber`), 404 if that graphic has no `graphic`. Keep the old URL `lernseiten/{lesson}/grafik` (no nr) redirecting to `nr=1` only if it's trivial; otherwise drop it (URLs are signed and short-lived anyway).
- `LessonView::page()`: `graphics` = map position → `{ url, beschreibung }` for finished graphics (signed, `v` = graphic `updated_at`); keep `hero` = graphics[1] for the top. Strip `grafik` blocks whose graphic isn't finished; if a finished graphic 2–3 has no block anywhere, append `{typ:'grafik', nr}` to the last section (so it's never lost).
- Frontend: `LessonPage` gets `graphics: Record<number, LessonHero>`; `LessonBlock` renders `<HeroFrame>` for `typ === 'grafik'` with the matching graphic (pass `graphics` down) plus its `beschreibung` as caption (muted, small).
- Parent props: per graphic `{ nr, error, canRegenerate }` list. `ReviewNotice`: one line per graphic error («Grafik 2: …»). `ParentToolbar`: «Grafik neu erstellen» becomes one menu entry per existing graphic («Grafik 1 (oben)», «Grafik 2», …) with the existing confirm dialog. `GenerationStatus`: show «Grafik 2 von 3 wird gebaut» from step `grafik-{n}` and the planned count.
- Tests: parent and shared props contain `graphics`; block for unfinished graphic removed; orphan graphic appended; signed URL per graphic works and rejects unsigned; regenerate menu data per graphic.

**Commit:** «Mehrere Grafiken anzeigen»

---

### Task 6: Form

**Files:** `resources/js/pages/lessons/Create.vue` (maybe a small `GraphicsField.vue` component next to `PhotoPicker.vue`).

- Replace the «Interaktive Grafik erstellen» checkbox with a fieldset «Grafiken»: three radio cards «Keine», «KI entscheidet» (default), «Selbst beschreiben».
- «Selbst beschreiben»: list of up to 3 rows, each with textarea «Was soll Grafik {n} zeigen? Was kann man damit tun?» (max 500, counter) and a select «Muster» (`KI wählt` + the six `HeroPattern` labels — pass them as a prop from `LessonController::create`), remove button per row (not for the last remaining), «+ Weitere Grafik» button (hidden at 3). Note under the list: «Grafik 1 steht oben auf der Seite, weitere im passenden Abschnitt. Jede Grafik verlängert die Erstellung um einige Minuten und kostet etwa $0.30–0.60.»
- Form data `graphics_mode`, `graphics: {beschreibung, muster}[]`; send `graphics` only for `custom`. Errors per row (`graphics.0.beschreibung`).
- Submit stays enabled as today (graphics don't count as a source).
- Verify: types, check, build; feature tests from Task 2 cover submission.

**Commit:** «Formular: Grafiken selbst beschreiben»

---

### Task 7: Edit view

**Files:** `resources/js/pages/lessons/Edit.vue`, `LessonContentController` (if needed).

- Show `grafik` blocks as a non-editable row «Grafik {nr}: {beschreibung or plan idea}» with the existing «Baustein entfernen» button; removing it hides that graphic on the page (it stays stored and can be regenerated). Make sure `update()` accepts `grafik` blocks (validator) and doesn't choke on them.
- Test: saving content with and without a `grafik` block.

**Commit:** «Bearbeiten: Grafik-Bausteine»

---

### Task 8: Remove old columns, docs

- Migration `2026_10_02_150000_drop_hero_columns_from_lessons_table.php`: drop `hero`, `hero_plan`, `hero_error`, `with_hero` (`down()` re-adds them and copies position 1 back; comment what's lost).
- Remove every remaining use (`grep -rn "hero_plan\|hero_error\|with_hero\|->hero\b\|'hero'" app resources tests database`), `Lesson` fillable/casts/docblock. `HeroValidator`, `HeroPattern`, `HeroFrame`, `hero-base.css`, `lesson-hero.blade.php` keep their names (they describe a graphic document; renaming is churn).
- Design doc «Teil 2»: mark implemented, note decisions (fixed jobs per position, fallback placement, old URL).
- Full verification incl. `npm run build`.

**Commit:** «Alte Grafik-Spalten entfernt, Docs Teil 2»

---

### Task 9: Real API check (manual, by the user)

Custom mode with 3 wishes (one deliberately unrelated to the photos): check placement, the «passt nicht» hint, costs per graphic on the Kosten page, and that the analysis schema still compiles (grammar size).
