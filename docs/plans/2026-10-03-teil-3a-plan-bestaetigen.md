# Teil 3a – Plan bestätigen: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The generation stops after a cheap planning step. The parents see the plan (title, key idea, sections, additions, graphics), edit it and confirm it. Only then do the expensive steps run: text part, modules, check, graphics. Misread photos or weak graphic ideas show up before they cost money.

**Design:** `docs/plans/2026-10-02-neue-lernseite-design.md`, section «3a Plan bestätigen, bevor es teuer wird».

**Architecture:**
- **No new API call.** Today's analysis call (step 1 of `AnalyzeLesson`: source, subject, summary, additions, graphic plans, with the photos) already is the «light planning call» of the design. It gets three more fields (`title`, `key_idea`, `sections[]` with `title` + `goal`) and becomes the planning step. The design's extra call would cost a second photo read for nothing.
- `AnalyzeLesson` (action + job) is split:
	- `PlanLesson`: the analysis call. It stores subject, summary, additions and graphic plans as today, plus the new plan in `lessons.plan`.
	- `WriteLesson`: the text part, modules, repair, validation, storing the content and deleting the photos. It is today's second half of `AnalyzeLesson`, unchanged except that the prompts get the confirmed plan.
- **Two chains.**
	- `GenerationPipeline::plan()` dispatches `PlanLesson`. At the end the job either sets status `planned` and stops (`lessons.review_plan` true), or confirms the plan itself and calls `GenerationPipeline::write()`.
	- `write()` dispatches `WriteLesson` → `CheckLesson` → one `GenerateLessonGraphic` per graphic **that has a plan** (known now, so no more empty jobs per possible position) → `FinishLesson`.
	- `start()` (used by create and retry) picks the right chain:
		- content exists → graphics/finish only (as today);
		- plan confirmed (`plan_confirmed_at`) → `write()`;
		- otherwise → `plan()`.
- **Status `planned`** («Plan prüfen»). Show.vue renders a `PlanReview` component instead of the progress. Polling stops.
- **The plan the parents confirm** is stored in `lessons.plan` and goes into the prompts of every later step:
	- page: binding title, key idea and sections;
	- modules/check/repair through `Prompts::context()`: key idea, section titles, the parents' note and removed additions.
	- Graphic edits go straight into `lesson_graphics.plan` (pattern + idea), as today.
- **Expiry:** `lessons:expire-plans` runs daily. Lessons that stay `planned` for more than 7 days are deleted with `DeleteLesson`, which deletes the photos too.
- **Costs:** the plan shows the costs so far (sum of `generations.cost_usd` for the lesson) and a rough estimate for the rest. The estimate is the average cost per step over the last 20 finished lessons; graphics are counted per planned graphic. A fixed fallback is used when there is no history.

**Tech Stack:** Laravel 13, Pest 4, Inertia 3 + Vue 3 + Tailwind 4. No new dependencies.

**Conventions:** as in `CLAUDE.md`:
- English keys, enum values and comments; German only for UI text, labels, validation messages and prompt prose (Swiss spelling, no «ß», quotes «…»);
- tabs;
- slim controllers: authorize, call one action, redirect. Logic goes in `app/Actions/Lessons/*` and `app/Actions/Generation/*`, props in `app/Http/PageData/LessonPage.php`;
- Pest TDD with `FakeLanguageModel`;
- commit messages in German with `Co-Authored-By`; stage files by name and never commit `public/build`;
- test migrations on a copy of `database/database.sqlite` in the scratchpad and back it up before migrating the real one;
- after the schema change, run `php artisan lessons:check-schemas` (real API, < $0.01);
- `php artisan queue:restart` after code changes.

**Plan shape (`lessons.plan`, json):**

```json
{
	"title": "Fotosynthese",
	"key_idea": "Pflanzen bauen mit Licht aus CO₂ und Wasser Traubenzucker auf.",
	"sections": [
		{ "title": "Was die Pflanze braucht", "goal": "Licht, CO₂ und Wasser als Zutaten erkennen." }
	],
	"note": null,
	"removed_additions": []
}
```

- `note` is the parents' remark («mehr Gewicht auf …»). It is null until they write one.
- `removed_additions` holds the additions the parents struck. They stay out of `lessons.additions`, and the prompts say to leave them out. They are kept in the plan because the summary still mentions them with «(ergänzt)».
- Section count stays within `config('lessons.scope')` → `sections` (e.g. «1–3»); the parents may remove sections down to 1.

---

### Task 1: Data and status

**Files:**
- `app/Enums/LessonStatus.php`: `Planned = 'planned'`, label «Plan prüfen».
- New migration `2026_10_03_120000_add_plan_to_lessons_table.php`: `plan` json nullable, `plan_confirmed_at` timestamp nullable, `review_plan` boolean default false. Existing lessons don't stop.
- `app/Models/Lesson.php`: fillable and casts (`plan` array, `plan_confirmed_at` datetime, `review_plan` boolean), `isPlanned()`.
- `resources/js/pages/Dashboard.vue`: status union gets `'planned'`, with its own badge colour.
- `resources/js/pages/lessons/Show.vue`: status union gets `'planned'`.
- Tests: `tests/Feature/Lessons/LessonPlanTest.php` (new).

Steps:
1. Write a failing test: a lesson with status `planned` shows «Plan prüfen» on the dashboard props.
2. Back up `database/database.sqlite`, test the migration on a copy in the scratchpad, then migrate.
3. Add the enum case, model casts and dashboard badge. Run the tests, then commit.

### Task 2: Planning fields in the analysis

**Files:**
- `app/Lessons/Ai/Schemas.php`: `analysis()` gets `title`, `key_idea` and `sections` (array of objects with `title`, `goal`). The descriptions are German: «Titel der Lernseite», «Was das Kind nach dem Lernen verstanden haben muss, ein Satz», «Geplante Abschnitte mit Titel und einem Satz, was er erklärt».
- `app/Lessons/Ai/Prompts.php`:
	- `analysis()`: the example gets the new fields. Step 1's line lists them.
	- `pageRequest()`: a new `planText()` with title, key idea, sections («verbindlich, in dieser Reihenfolge»), the note and the removed additions («Diese Ergänzungen haben die Eltern gestrichen, lass sie weg: …»). This replaces nothing; `pagePlanText()` for the graphics stays.
	- `context()` (non-child): the key idea, the section titles and the parents' note, if there is a plan, plus the removed additions. The child view (`forChild`) gets only the key idea.
- `resources/prompts/analysis.md`: step 1 lists the new fields. Section «3. Planen» describes title, key idea and sections. Step 2 says the plan in the user prompt is binding, together with the parents' note and the removed additions. `meta.title` and `meta.key_idea` take the values from the plan.
- `database/fixtures/lessons/*`: the fake model returns the analysis from its fixture. Make sure `FakeLanguageModel` delivers the new fields; check how it builds the analysis answer.
- Tests: `tests/Unit/Lessons/PromptsTest.php` (plan text in the page prompt; note and removed additions in context), `tests/Unit/Lessons/SchemasTest.php` (size guard).

Steps (TDD for each prompt change): test → fail → implement → pass. Then run `php artisan lessons:check-schemas`; every line must say OK. Commit.

### Task 3: Split `AnalyzeLesson` into `PlanLesson` and `WriteLesson`

**Files:**
- New `app/Actions/Generation/PlanLesson.php`: the first half of today's `AnalyzeLesson::handle()`:
	- photos;
	- subject reset;
	- analysis call;
	- `source.readable` check;
	- subject/summary/additions;
	- `storeGraphicPlans()`;
	- then `plan` = `{title, key_idea, sections, note: null, removed_additions: []}`, with `plan_confirmed_at = null`.
	
	Sections beyond the scope maximum are cut. With no section at all → `GenerationFailed('Die KI hat keinen gültigen Plan geliefert.')`.
- New `app/Actions/Generation/WriteLesson.php`: the second half (page, modules, repair, validate, store, delete photos). It loads the photos itself, with the same «Es sind keine Fotos mehr vorhanden» guard.
	- After the page call, `meta.title` is overwritten with `plan.title`. The parents may have renamed it, and the title is theirs.
	- `assemble()`/`onlyAllowed()`/`detectedSubject()` move with their halves. If both need one, keep it in `WriteLesson` and make it static there.
- Jobs: rename `app/Jobs/AnalyzeLesson.php` → `PlanLesson.php` (step `analysis`) and add `WriteLesson.php` (step `page`; the action sets `modules` as today).
	- Keep a tiny `App\Jobs\AnalyzeLesson` subclass of `WriteLesson` only if jobs may still be queued at deploy time. Otherwise delete it: the deploy stops the queue anyway (see STATUS «Deploy»). **Decision: delete it, the queue is stopped during deploy.**
- Delete `app/Actions/Generation/AnalyzeLesson.php`.
- `GenerationPipeline`: `start()`, `plan()`, `write()` as described under Architecture.
	- `PlanLesson` (job) ends with: `review_plan` → status `planned`, step null; else → `ConfirmPlan` action (Task 4) without changes.
	- Graphic jobs in `write()`: `graphics()->whereNotNull('plan')->whereNull('graphic')->pluck('position')`.
- `resources/js/components/lesson/GenerationStatus.vue`: steps `analysis` («Stoff lesen und planen»), `page` («Erklärungen schreiben»), `modules`, `check`, graphics.
- `app/Http/PageData/LessonPage.php` `plannedGraphics()`: once a plan exists (`plan !== null`), the graphics with a plan. Before that, as today.
- Tests: move and adapt `tests/Feature/Lessons/LessonGenerationTest.php` (it calls the analysis job/action today).
	- New cases: `review_plan` false runs through to `review`, as today.
	- `review_plan` true stops at `planned` with no page call (assert the fake model's requests: only `analysis`).
	- Retry after a failure in the write step skips planning.
	- Retry after a failure in planning plans again.
	- Graphic jobs are only dispatched for planned graphics (`Bus::fake()`).

Steps: tests first, for the pipeline decisions and the stop. Then move the code, run the full suite and phpstan, and commit.

### Task 4: Confirm, re-plan, discard (backend)

**Files:**
- New `app/Actions/Lessons/ConfirmPlan.php` `handle(Lesson $lesson, array $data)`. In a transaction:
	- write the edited plan (title, sections, note, removed additions);
	- set `additions` = the remaining additions;
	- apply the graphic edits:
		- update `plan.idea` / `plan.pattern`;
		- delete removed rows, but only unbuilt ones (`graphic` null);
		- create new rows (position = first free 1–3, `request` = description, `plan` = {pattern, idea});
	- set `plan_confirmed_at = now()`.
	
	Then `GenerationPipeline::write($lesson)`. Called with `[]` by `PlanLesson` when there is no review.
- New `app/Actions/Lessons/ReplanLesson.php` `handle(Lesson $lesson, ?string $note)`: store the note in the request line for the analysis and dispatch `GenerationPipeline::plan()`.
	- `Prompts::analysis()` gets «Anmerkung der Eltern zum letzten Plan: …» if `plan.note` is set.
	- The old graphic plans are replaced by `storeGraphicPlans()` as today.
- Discard = existing `DELETE lernseiten/{lesson}` (`DeleteLesson`). Nothing new.
- New `app/Http/Requests/ConfirmPlanRequest.php`:
	- `title` required string max 120;
	- `sections` array min 1, max = upper bound of the scope (e.g. 3 for «normal»); `sections.*.title` required max 120; `sections.*.goal` nullable max 300;
	- `note` nullable max 500;
	- `additions` array of strings, which must be a subset of the stored additions (closure rule);
	- `graphics` array max 3; `graphics.*.number` nullable integer (null = new); `graphics.*.pattern` required enum `GraphicPattern`; `graphics.*.idea` required max 1000.
	
	German messages and attributes like `StoreLessonRequest`.
- `app/Http/Controllers/LessonController.php`: `confirmPlan(ConfirmPlanRequest, Lesson, ConfirmPlan)` and `replan(Request, Lesson, ReplanLesson)`. Both: `Gate::authorize('update')`, `abort_unless($lesson->isPlanned(), 422)`, call the action and redirect to `lessons.show`.
- `routes/web.php`:
	- `PUT lernseiten/{lesson}/plan` → `lessons.plan.confirm`;
	- `POST lernseiten/{lesson}/plan/neu` → `lessons.plan.replan` (throttled like `regenerate`).
	
	Run `php artisan wayfinder:generate` if the project generates routes (check `resources/js/routes`).
- Tests in `LessonPlanTest.php`:
	- confirm starts the write chain with the edited plan in the page prompt;
	- a struck addition is gone from `additions` and listed as removed in the prompt;
	- graphic edits (change, remove, add) land in `lesson_graphics`;
	- a foreign addition is rejected;
	- confirm and replan on a non-planned lesson → 422;
	- another user → 403;
	- replan sends the note in the analysis request.

### Task 5: Plan review page (frontend)

**Files:**
- New `resources/js/components/lesson/PlanReview.vue`, shown by `Show.vue` when `lesson.status === 'planned'` and `parent` is set.
	- Header: «Plan prüfen». One sentence: «Prüfe den Plan, bevor die Seite erstellt wird. Bis jetzt hat die Erstellung {cost} gekostet, der Rest etwa {estimate}.»
	- Title (input) and key idea (read-only text).
	- Sections: a list with title input, goal text, remove button (disabled at 1), and up/down to reorder.
	- Additions: a list with a strike/restore toggle. Only shown when there are additions.
	- Graphics: one card each with a pattern select (labels from `GraphicPattern::label()`) and an idea textarea, plus remove. «Grafik hinzufügen» up to 3 (pattern + description).
	- Note: a textarea «Anmerkung zum Plan (optional)».
	- Actions:
		- **Erstellen** (primary, `useForm` PUT);
		- **Nochmals planen** (POST with the note; a confirm dialog says it costs another planning call);
		- **Verwerfen** (existing delete dialog/route; reuse what ParentToolbar does).
	- Use `InputError` per field like Edit.vue. German UI, Swiss spelling.
- `app/Http/PageData/LessonPage.php`: for the parents when planned, `plan` → `{title, key_idea, sections, note, additions, graphics: [{number, pattern, idea, error}], patterns: [{value, label}], cost: {spent, estimate}}`.
- New `app/Lessons/CostEstimate.php` (static helper, pure query logic):
	- `spent(Lesson)`;
	- `remaining(Lesson)` = average `cost_usd` per step (`page`, `modules`, `repair`, `check`, `graphic`) over the last 20 lessons with status review/published, times 1 per step, graphics × planned count. Fallback constants when there's no history (page 0.25, modules 0.10, check 0.08, graphic 0.30 USD; adjust after looking at `/kosten`).
	- Formatted like the Kosten page (check `CostOverview` for the formatter).
- `resources/js/types`: `LessonPlan` type.
- Tests: page props for a planned lesson (`LessonPlanTest`), `CostEstimate` unit test with seeded generations, and the fallback without history.
- Run `npm run types:check`, `npm run check:fix`, `npm run check`.

### Task 6: «Plan vorher anzeigen» in the form

**Files:**
- `resources/js/pages/lessons/Create.vue` (or the options component it uses): checkbox «Plan vorher anzeigen», default on. In easy mode it is shown under the preset, in advanced mode with the other options. «Wie letztes Mal» takes it from the last lesson (check how `CreateLessonPage` builds that preset).
- `app/Http/Requests/StoreLessonRequest.php`: `review_plan` boolean, required.
- `app/Actions/Lessons/CreateLesson.php`: store `review_plan`.
- Tests: the store request stores the flag; true stops at `planned`; false runs through.

### Task 7: Expiry

**Files:**
- New `app/Console/Commands/ExpirePlans.php` (`lessons:expire-plans`): lessons with status `planned` and `updated_at` older than 7 days → `DeleteLesson::handle()`. Prints the count.
- `routes/console.php`: `Schedule::command('lessons:expire-plans')->daily()`.
- Tests: an expired plan is deleted (soft) and its photos are gone (`Storage::fake('lesson-images')`); a fresh plan and an old `review` lesson stay.

### Task 8: Docs and final checks

- `docs/plans/STATUS.md`: Teil 3a done, decisions (analysis call = planning call; two chains; expiry 7 days), test items for Marcel (plan page in light/dark, confirm/replan/discard, costs).
- Design doc section 3a: note «Umsetzung» with the decisions above.
- Full checks: `composer format:check`, `php artisan test --compact`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run types:check`, `npm run check`, `npm run build` (don't commit `public/build`), `php artisan lessons:check-schemas`.
- Final review with a code-reviewer subagent, then fix its findings.

### Task 9: Real API check (manual, by Marcel)

- `php artisan queue:restart`.
- One lesson with photos and the plan review on: edit sections, strike an addition, change a graphic, confirm. Check that the page follows the plan.
- One lesson with the review off: it runs through as before.
- On `/kosten`, compare the cost per step with the estimate shown on the plan.
