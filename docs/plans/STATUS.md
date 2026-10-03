# Status

## Stand

- **Datum:** 2026-10-03
- **Branch:** `main` (`feature/neue-lernseite` merged 2026-10-03, not deployed)
- **Last commit:** «Teil 3a: Planen und Schreiben getrennt»
- **Checks:** 796 Pest tests green, phpstan 0, `composer format:check`, `npm run types:check`, `npm run check`, `npm run build` clean; `php artisan lessons:check-schemas` all OK.
- **Local DB:** fully migrated (no pending migrations). Backups `database/database.sqlite.bak-2026-10-0*` (7 files, gitignored) can be deleted once everything works.

## Erledigt

Overall design: `docs/plans/2026-10-02-neue-lernseite-design.md`. Every part has its own plan in `docs/plans/`.

- **Teil 4 – Kosten** (`teil-4-kosten.md`): model + effort per step (`config/lessons.php` `models`), one check call returning corrections (`app/Lessons/Corrections.php`, only leaf values / scalar lists, same type, grouped per item), cost per step on the Kosten page.
- **Soft delete:** lessons soft-deleted, content/attempts/graphics emptied on delete, costs kept via `generations.user_id`; running jobs stop when the lesson is gone (`LessonGone`).
- **Teil 1 – Fotos als Rahmen, Auftrag** (`teil-1-auftrag.md`): optional photos + optional prompt, gaps filled from subject knowledge and marked `origin: added` (parents only).
- **Teil 3b – Upload** (`teil-3b-upload.md`): drag & drop, paste, reorder, camera, quality warning (`PhotoPicker.vue`, `lib/imageQuality.ts`).
- **Teil 2 – Grafiken** (`teil-2-grafiken.md`): up to 3 graphics (`lesson_graphics`), one job per position, `graphic` blocks in sections, hidden flag via edit view, regenerate per graphic without taking published pages offline.
- **Teil 3c – Zweck, Umfang, Module** (`teil-3c-umfang.md`): purpose/scope/allowed modules, quiz optional, counts in `config('lessons.scope')`.
- **Formular Einfach | Erweitert** (`einfach-erweitert.md`): presets (Kurz & schnell / Normal / Prüfung / Wie letztes Mal), AI detects the subject.
- **Analysis split** into two API calls (`analysis` + `page`) because the API rejected the schema as too large.
- **Code style refactor** (`code-style.md`): English keys/values (data migrated), actions + page-data classes (controllers 1048 → 391 lines), English names and comments, tabs, PHP-CS-Fixer instead of Pint.
- **Teil 5 – Fachprofile** (`teil-5-fachprofile.md`), Tasks 1–6 (design doc section «Umsetzung» has blocks/modules per profile and probe sizes):
	- Framework: `App\Lessons\Profile` (science, general, languages, math, geometry, german), resolved from subject unless the parent picks one; schemas filtered per profile.
	- Languages: `vocabulary`, `conjugation`, speech via browser, «almost» for missing accents (`AnswerResult`).
	- Math: KaTeX (lazy-loaded), `worked_solution`, `exercises` module with Swiss number/fraction/unit checking (`ExerciseAnswer`).
	- Geometry: `figure` block rendered by `FigureBlock.vue` (points, lines, angles; no areas).
	- Übungen: `exercises` is a module the parents choose (`Lesson::MODULES`), ticked by default and in every preset, used only by math and geometry; 8 / 12 / 20 per scope (validator max 20). Migration `2026_10_03_100000` adds it to stored choices (they used to get exercises anyway). Only foreign modules chosen → quiz.
	- German (Task 5): `find_the_mistake` module (tap the wrong word, write the correction; checked by `MistakeAnswer`, mirrored in `lib/mistake.ts`), `cloze.case_sensitive` (german schema only), fixture `das-dass`, editors in Edit.vue. German keeps all base blocks/modules; probe: modules (german) 3637 bytes OK.
	- `php artisan lessons:check-schemas` probes every schema against the real API.
	- Final review done (2026-10-03), fixed: «24 cm2» counts for «cm²» (units with ²/³), mixed numbers («1 1/2») in fraction exercises, `mistake_word` follows its word when the sentence is edited (else null, must be tapped again; new sentences start with null), prefill doesn't solve punctuation-only mistakes, no «fast richtig» in case-sensitive German gaps, tolerance that is no number blocks saving.
	- KaTeX is never loaded during SSR (hydration mismatch, `lib/math.ts`).
- **Workflow:** `CLAUDE.md`, this file (loaded by a SessionStart hook in `.claude/settings.json`), `/handover` skill.

## Offen

1. **Teil 3a – Plan bestätigen** (`docs/plans/2026-10-03-teil-3a-plan-bestaetigen.md`): Tasks 1–3 done, 4–8 open (confirm/replan backend, PlanReview page, form checkbox, expiry, docs).
	- Done: status `planned` («Plan prüfen»), `lessons.plan` / `plan_confirmed_at` / `review_plan` (migrated); the analysis also returns `title`, `key_idea`, `sections` (schema probe OK, 2134 bytes); plan binding in the page prompt, key idea/sections/note/struck additions in `Prompts::context()`.
	- `AnalyzeLesson` split into `PlanLesson` (analysis → plan) and `WriteLesson` (page, modules, repair; title from the plan). `GenerationPipeline::start()` → graphics only / `write()` / `plan()`; graphic jobs only for planned graphics. Retry after a write failure skips planning.
	- Until Task 4: without review the `PlanLesson` job sets `plan_confirmed_at` itself; `review_plan` isn't in the form yet (Task 6), so every lesson runs through.
2. **Teil 6 – Aussprache mit ElevenLabs** (`docs/plans/2026-10-03-teil-6-aussprache.md`): Tasks 1–3 done (`config/speech.php`, client `app/Lessons/Speech/ElevenLabs.php`, `Texts`, table `speech_clips`, `generations.credits`, disk `speech`; local DB migrated, backup `database.sqlite.bak-2026-10-03-speech`; job `SpeakLesson` after the check, only for language lessons with key and voice), Tasks 4–8 open (serving + props, frontend, backfill command, docs). Clips per word generated once and shared (`speech_clips`), browser voice stays as fallback, never fails a lesson. Free plan, 10'000 credits/month (~30 lessons); `ELEVENLABS_API_KEY` is in the local `.env` (needs read permissions for voices and user).
3. **Deploy** `main` (see `docs/deployment.md`).

Known smaller follow-ups (not blocking): graphics mode and models per profile (design) not implemented; `check.md` doesn't verify figure coordinates against angles in the text; edit view can't re-add removed blocks; a worker killed mid-regeneration leaves the lesson locked (`regenerate-*` step); check corrections can't fill null fields (`tolerance`, `unit`) and a corrected mistake sentence keeps its old `mistake_word`; PHP/TS answer checkers have no shared parity tests (no JS test runner).

## Entscheide

- 2026-10-02: Photos are the binding frame; the Auftrag narrows focus; additions marked `added`, visible to parents only.
- 2026-10-02: Graphics: Keine / KI entscheidet / Selbst beschreiben (max 3); graphic 1 on top, others in their section; removing a graphic block hides it, regenerating brings it back.
- 2026-10-02: Easy mode default (photos, Auftrag, child, preset); AI detects subject; level from the child.
- 2026-10-02: Cost: Sonnet for modules/check/repair, Opus for analysis/page/graphic (graphic at effort medium).
- 2026-10-02: English keys and comments, tabs everywhere, PHP-CS-Fixer (`composer format`).
- 2026-10-02: Slim controllers; actions with one `handle()`; page data in `app/Http/PageData/*::props()`; no trivial wrapper helpers.
- 2026-10-02: Profiles: only blocks/modules of the lesson's profile are sent to the API; profile stored only when the parent picks it.
- 2026-10-03: Math «Übungen» selectable, count by scope (8/12/20) instead of a fixed 20.
- 2026-10-03: Teil 3a: the existing analysis call becomes the planning call (adds title, key idea, sections); no extra photo read.
- 2026-10-03: `public/build` stays in git (Hostpoint has no Node); merged `feature/neue-lernseite` into `main`.
- 2026-10-03: Aussprache: ElevenLabs voice «Alice» (premade) with `eleven_v4`; the free plan can't use library voices via the API (native French voices need a paid plan).
- 2026-10-03: Project status lives in this file; updated after every completed task (`CLAUDE.md`).

## Für Marcel zu testen / zu tun

- **Restart the queue worker** (`php artisan queue:restart`) — code changed a lot since the last restart.
- **Browser check** (nothing has been seen in a browser yet): form in both modes, presets, drag & drop/paste/reorder, quality warning thresholds (`lib/imageQuality.ts`), graphics custom mode, edit view (hide graphic, remove block), Lernstand; profile pages from the fixtures (passé composé, Dreisatz, Winkel an Parallelen) in light and dark mode — `FigureBlock` especially.
- **Fehler finden (Edit view):** change a sentence → the selected wrong word follows it or must be tapped again; save without a word shows an error.
- **Übungen:** tested by Marcel 2026-10-03, works well. Layout fixes after his test (formulas in titles on Übersicht/Kosten/tab, space after a box): check again.
- **Real API runs:** one lesson per profile; check costs per step on `/kosten` against the old ~$1.05 per lesson.
- **Deploy:** `deploy.sh` runs migrations; stop the queue during deploy (data migrations to English keys), restart it after; run `php artisan lessons:check-schemas` on the server once. Env vars for per-step models are now `LESSON_MODEL_ANALYSIS|MODULES|CHECK|GRAPHIC` (+ `LESSON_EFFORT_*`), optional.

## Risiken und Stolpersteine

- **API grammar size limit:** byte size does not predict it (math modules with five modules failed at 3647 bytes; geometry page fails at 4558, passes at 4116). Run `php artisan lessons:check-schemas` after every schema or model change; drop blocks/modules per profile to fit.
- **Queue worker** keeps old code in memory — restart after changes.
- **`database/database.sqlite` is a copy of production data:** test migrations on a copy in the scratchpad first, back up before migrating.
- Subagents have stalled on long silent commands — keep tool calls short.

## So arbeiten wir

- Read `CLAUDE.md` (conventions, checks, pitfalls).
- PHP: `composer format`, `composer format:check`, `php artisan test --compact`, `vendor/bin/phpstan analyse --memory-limit=1G`. Frontend: `npm run check:fix`, `npm run types:check`, `npm run build` (commit `public/build`, Hostpoint has no Node).
- Write logic in actions, props in page-data classes; English keys/comments; German only in UI text and prompt prose; tabs.
- Plans first (`docs/plans/`), then implementation task by task; update this file after each completed task.
