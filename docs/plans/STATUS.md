# Status

## Stand

- **Datum:** 2026-10-03
- **Branch:** `feature/neue-lernseite` (85 commits ahead of `main`, not merged, not deployed)
- **Last commit:** «Formeln in Titeln, Abstand nach Bausteinen»
- **Checks:** 735 Pest tests green, phpstan 0, `composer format:check`, `npm run types:check`, `npm run check`, `npm run build` clean; `php artisan lessons:check-schemas` all OK.
- **Local DB:** fully migrated (no pending migrations). Backups `database/database.sqlite.bak-2026-10-0*` (6 files, gitignored) can be deleted once everything works.

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
- **Teil 5 – Fachprofile** (`teil-5-fachprofile.md`), Tasks 1–4:
	- Framework: `App\Lessons\Profile` (science, general, languages, math, geometry, german), resolved from subject unless the parent picks one; schemas filtered per profile.
	- Languages: `vocabulary`, `conjugation`, speech via browser, «almost» for missing accents (`AnswerResult`).
	- Math: KaTeX (lazy-loaded), `worked_solution`, `exercises` module with Swiss number/fraction/unit checking (`ExerciseAnswer`).
	- Geometry: `figure` block rendered by `FigureBlock.vue` (points, lines, angles; no areas).
	- Übungen: `exercises` is a module the parents choose (`Lesson::MODULES`), ticked by default and in every preset, used only by math and geometry; 8 / 12 / 20 per scope (validator max 20). Migration `2026_10_03_100000` adds it to stored choices (they used to get exercises anyway). Only foreign modules chosen → quiz.
	- German (Task 5): `find_the_mistake` module (tap the wrong word, write the correction; checked by `MistakeAnswer`, mirrored in `lib/mistake.ts`), `cloze.case_sensitive` (german schema only), fixture `das-dass`, editors in Edit.vue. German keeps all base blocks/modules; probe: modules (german) 3637 bytes OK.
	- `php artisan lessons:check-schemas` probes every schema against the real API.
- **Workflow:** `CLAUDE.md`, this file (loaded by a SessionStart hook in `.claude/settings.json`), `/handover` skill.

## Offen

1. **Teil 5, Task 6 – Docs:** mark Teil 5 implemented in the design doc, list per-profile blocks/modules and probe sizes.
2. **Final review** of Teil 5 (code-reviewer subagent), fix findings.
3. **Teil 3a – Plan bestätigen** (design doc section «3a»): planning call, status `planned`, parents edit/confirm the plan before the expensive steps. No implementation plan written yet — write it first (with English keys, actions, page data).
4. **Merge into `main`** after Marcel has tested; deploy (see `docs/deployment.md`).

Known smaller follow-ups (not blocking): `check.md` doesn't verify figure coordinates against angles in the text; parents can't switch off `exercises` in math lessons; edit view can't re-add removed blocks; a worker killed mid-regeneration leaves the lesson locked (`regenerate-*` step).

## Entscheide

- 2026-10-02: Photos are the binding frame; the Auftrag narrows focus; additions marked `added`, visible to parents only.
- 2026-10-02: Graphics: Keine / KI entscheidet / Selbst beschreiben (max 3); graphic 1 on top, others in their section; removing a graphic block hides it, regenerating brings it back.
- 2026-10-02: Easy mode default (photos, Auftrag, child, preset); AI detects subject; level from the child.
- 2026-10-02: Cost: Sonnet for modules/check/repair, Opus for analysis/page/graphic (graphic at effort medium).
- 2026-10-02: English keys and comments, tabs everywhere, PHP-CS-Fixer (`composer format`).
- 2026-10-02: Slim controllers; actions with one `handle()`; page data in `app/Http/PageData/*::props()`; no trivial wrapper helpers.
- 2026-10-02: Profiles: only blocks/modules of the lesson's profile are sent to the API; profile stored only when the parent picks it.
- 2026-10-03: Math «Übungen» selectable, count by scope (8/12/20) instead of a fixed 20.
- 2026-10-03: Project status lives in this file; updated after every completed task (`CLAUDE.md`).

## Für Marcel zu testen / zu tun

- **Restart the queue worker** (`php artisan queue:restart`) — code changed a lot since the last restart.
- **Browser check** (nothing has been seen in a browser yet): form in both modes, presets, drag & drop/paste/reorder, quality warning thresholds (`lib/imageQuality.ts`), graphics custom mode, edit view (hide graphic, remove block), Lernstand; profile pages from the fixtures (passé composé, Dreisatz, Winkel an Parallelen) in light and dark mode — `FigureBlock` especially.
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
- PHP: `composer format`, `composer format:check`, `php artisan test --compact`, `vendor/bin/phpstan analyse --memory-limit=1G`. Frontend: `npm run check:fix`, `npm run types:check`, `npm run build` (never commit `public/build`).
- Write logic in actions, props in page-data classes; English keys/comments; German only in UI text and prompt prose; tabs.
- Plans first (`docs/plans/`), then implementation task by task; update this file after each completed task.
