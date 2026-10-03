# Teil 1 – Fotos als Rahmen, Auftrag, Lücken ergänzen: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Replace «Fotos | Thema» and «Hinweise» with optional photos plus an optional Auftrag (prompt). Photos are the binding frame. The AI may fill gaps from subject knowledge, but marks every added block, and only parents see those markers.

**Architecture:** New columns `lessons.prompt`, `lessons.photo_count`, `lessons.additions`. The analysis gets one of three source texts (photos / prompt / both) plus the Auftrag. `Prompts::context()` passes Auftrag and additions to every later step. Blocks, quiz questions, sort terms, flashcards and the cloze module get a `herkunft` field (`foto` | `ergaenzt`). `LessonView` strips `herkunft` for the child's view and for lessons without photos. One small `OriginBadge` component shows the marker.

**Tech Stack:** Laravel 13, Pest 4, Inertia 3 + Vue 3 + Tailwind 4.

**Design:** `docs/plans/2026-10-02-neue-lernseite-design.md`, section «Teil 1».

**Conventions:** same as `docs/plans/2026-10-02-teil-4-kosten.md` (German comments/UI, Swiss spelling, English test names, `vendor/bin/pint --dirty`, `php artisan test --compact`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run types:check`, `npm run check`, commit messages German with `Co-Authored-By` line, stage files by name, never commit `public/build` or `package-lock.json`).

**Old data:** Existing lessons keep `topic`/`notes` and have no `herkunft`. Everything must keep rendering and validating for them.

---

### Task 1: Data model

**Files:**
- Create: `database/migrations/2026_10_02_120000_add_prompt_to_lessons_table.php`
- Modify: `app/Models/Lesson.php`
- Modify: `app/Http/Controllers/DashboardController.php:36`, `app/Http/Controllers/CostController.php` (title fallback)
- Test: `tests/Unit/Lessons/LessonModelTest.php` (create)

**Migration:** `prompt` (text, nullable, after `notes`), `photo_count` (unsignedTinyInteger, default 0, after `prompt`), `additions` (json, nullable, after `source_summary`). Backfill `photo_count`: not needed (old photo lessons have `topic = null`, which keeps them «not from topic»). `down()` drops the three columns.

**Model:**
- Add the three fields to `#[Fillable]`, the `@property` docblock (`string|null $prompt`, `int $photo_count`, `list<string>|null $additions`) and `casts()` (`'additions' => 'array'`).
- `isFromTopic()`: `return $this->photo_count === 0 && ($this->topic !== null || $this->prompt !== null);` with docblock «Ohne Fotos erstellt (Thema oder Auftrag): Inhalt stammt aus dem Wissen der KI».
- New `displayTitle(): string`: `$this->title ?? $this->topic ?? ($this->prompt ? Str::limit($this->prompt, 60) : 'Neue Lernseite')`.

**Tests (write first):**
- `isFromTopic` is true for `topic` only, true for `prompt` with `photo_count 0`, false for `prompt` with `photo_count 2`, false for neither.
- `displayTitle` falls back title → topic → shortened prompt (prompt of 100 characters ends in `...` and is at most 63 characters) → «Neue Lernseite».

Use `Lesson::factory()->make([...])` (no DB needed). Then replace `$lesson->title ?? ($lesson->topic ?? 'Neue Lernseite')` in `DashboardController` and `->title ?? …->topic ?? 'Ohne Titel'` in `CostController` with `displayTitle()`. In CostController the eager load `lesson:id,title,topic,subject,child_id` must include `prompt`. The existing dashboard/costs tests must stay green.

**Commit:** «Lernseite: Auftrag, Anzahl Fotos und Ergänzungen speichern»

---

### Task 2: Upload request and store

**Files:**
- Modify: `app/Http/Requests/StoreLessonRequest.php`
- Modify: `app/Http/Controllers/LessonController.php` (`store`)
- Test: `tests/Feature/Lessons/LessonGenerationTest.php` (`upload()` helper, «from a topic» describe block, «validates the upload», «sends the photos, subject, level and notes…»)

**Rules:**
```php
'prompt' => ['nullable', 'string', 'max:1000'],
'images' => ['required_without:prompt', 'array', 'max:'.config('lessons.images.max_count')],
'images.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('lessons.images.max_upload_kb')],
```
Remove `source`, `topic`, `notes`. Messages: `images.required_without` → «Lade mindestens ein Foto hoch oder schreib einen Auftrag.», `prompt.max` → «Der Auftrag darf höchstens 1000 Zeichen lang sein.»; drop the topic/notes/images.required/min messages.

**Store:** `prompt` = trimmed input or null, `photo_count` = `count($images)`, no `topic`/`notes`.

**Tests (update first, see them fail):**
- `upload()` helper: drop `source` and `notes`, send `'prompt' => 'Prüfung am Freitag'`.
- Replace the «from a topic» describe block with «from a prompt»: prompt only creates a lesson with `photo_count 0`, `isFromTopic()` true, no images; photos + prompt stores both, `isFromTopic()` false; neither → `assertSessionHasErrors(['images' => 'Lade mindestens ein Foto hoch oder schreib einen Auftrag.'])`; 1001 characters → error on `prompt`. Keep «explains when the topic does not work» but feed it a prompt.
- «validates the upload»: adjust to the new rules.

Run the whole `LessonGenerationTest`; the prompt-text assertion in «sends the photos…» will fail until Task 4, so in this task change it to only assert images, Fach, Stufe and the child name, and move the Auftrag assertion to Task 4.

**Commit:** «Upload: Fotos und Auftrag statt Fotos oder Thema»

---

### Task 3: `herkunft` and `ergaenzungen` in schema, validator, fixtures and types

**Files:**
- Modify: `app/Lessons/Ai/Schemas.php` (`analysis`, `page`, `modules`)
- Modify: `app/Lessons/ContentValidator.php` (`rules`)
- Modify: `database/fixtures/lessons/fotosynthese.json`, `oekosystem.json`
- Modify: `app/Lessons/Ai/FakeLanguageModel.php` (`defaultResponse('analyse')`)
- Modify: `resources/js/types/lesson.ts`
- Test: `tests/Unit/Lessons/SchemasTest.php`, `tests/Unit/Lessons/ContentValidatorTest.php`

**Schema:** `$origin = ['type' => 'string', 'enum' => ['foto', 'ergaenzt'], 'description' => 'ergaenzt: nicht auf den Fotos, aus Fachwissen ergänzt']`.
- Add `'herkunft' => $origin` inside the `$block` closure (so every block type has it), to quiz items, `sortieren.begriffe` items, `karten.eintraege` items, and to the `lueckentext` object.
- `analysis()`: add `'ergaenzungen' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Pro Ergänzung ein Satz: was auf den Fotos fehlte und was aus Fachwissen ergänzt wurde. Leer, wenn nichts ergänzt wurde oder es keine Fotos gibt.']`.
- Add a constant `ContentValidator::ORIGINS = ['foto', 'ergaenzt']` and use it for the enum.

**Validator:** `'…herkunft' => ['sometimes', Rule::in(self::ORIGINS)]` for `abschnitte.*.bloecke.*.herkunft`, `module.quiz.*.herkunft`, `module.sortieren.begriffe.*.herkunft`, `module.karten.eintraege.*.herkunft`, `module.lueckentext.herkunft`. (`sometimes`: old content has no field.)

**Fixtures:** add `"herkunft": "foto"` to every block, quiz question, sort term, flashcard and the cloze object in both fixture files (a small PHP/`jq` script is fine; keep formatting: 4-space JSON). The `.hero.json` files are unchanged.

**Fake:** `defaultResponse('analyse')` gets `'ergaenzungen' => []`.

**Types:** `herkunft?: 'foto' | 'ergaenzt'` on every `LessonBlock` variant (use an intersection: `type Origin = { herkunft?: 'foto' | 'ergaenzt' }`), `QuizQuestion`, sort term, flashcard, `ClozeModuleData`.

**Tests (first):**
- ContentValidator: content without any `herkunft` is valid; `herkunft: 'buch'` on a block is rejected.
- Schemas: the existing «only uses features…», «accepts both fixtures…», «matches the responses of the fake model» stay green after the change; add an assertion that the analysis schema has `ergaenzungen` and a block has `herkunft`.
- The test «never sends the whole page as one schema» must stay green.

**Risk:** the analysis schema already sits near the API's grammar size limit («The compiled grammar is too large»). Task 9 checks this against the real API. If it fails, drop `herkunft` from `fakten`/`spalten` sub-entries first (it is on the block level only, which is already the case here) and report.

**Commit:** «Herkunft pro Baustein und Liste der Ergänzungen im Schema»

---

### Task 4: Prompts and generator

**Files:**
- Modify: `app/Lessons/Ai/Prompts.php` (`analysis`, `context`)
- Modify: `app/Lessons/LessonGenerator.php` (`analyze`)
- Modify: `resources/prompts/analyse.md`, `module.md`, `pruefung.md`
- Test: `tests/Feature/Lessons/LessonGenerationTest.php`

**`Prompts::analysis()` source text** (`$count = count($images)`):
- photos, no prompt: as today.
- prompt, no photos: «Erstelle den Textteil einer Lernseite nach dem Auftrag der Eltern. Es gibt keine Fotos, arbeite aus deinem Fachwissen (siehe «Nur ein Auftrag, keine Fotos»).»
- photos + prompt: «Erstelle den Textteil einer Lernseite aus diesem Foto / diesen N Fotos. Die Fotos sind der Rahmen, der Auftrag der Eltern setzt den Fokus (siehe «Fotos und Auftrag»).»
- legacy `topic` (retry of an old lesson): keep today's topic text.
- Lines below: Fach, Stufe, Interaktive Grafik as today, then `Auftrag der Eltern: {prompt}` if set, else legacy `Hinweise der Eltern: {notes}` if set.

**`Prompts::context()`** appends, when present: `Auftrag der Eltern: {prompt}` (or legacy notes) and `Ergänzt (nicht auf den Fotos):\n- …` from `additions`.

**`LessonGenerator::analyze()`:** store `'additions' => array_values(array_filter($data['ergaenzungen'] ?? [], 'is_string')) ?: null` together with `source_summary`.

**analyse.md:**
- Rename «Nur ein Thema, keine Fotos» to «Nur ein Auftrag, keine Fotos» and phrase it for an Auftrag (the Auftrag names topic and focus). Every `herkunft` is `ergaenzt` there, `ergaenzungen` stays empty.
- New subsection «Fotos und Auftrag»: photos are the binding frame (material, terms, definitions, level); the Auftrag picks focus, angle, style; book definitions win; additions must use the book's terms and not contradict it.
- New subsection «Lücken ergänzen»: if the photos are too thin for a complete page, or the Auftrag names a topic not on the photos, add it from subject knowledge at the right level; set `herkunft: "ergaenzt"` on every block that contains added material, `"foto"` otherwise; list each addition in `ergaenzungen`; mark added parts in `zusammenfassung` with «(ergänzt)» so later steps know.
- In section 2 mention that `zusammenfassung` marks additions with «(ergänzt)».

**module.md:** every quiz question, sort term, flashcard and the cloze module gets `herkunft`: `ergaenzt` if it asks about something marked «(ergänzt)» in the summary or listed under «Ergänzt», else `foto`. Without photos: always `ergaenzt`.

**pruefung.md:** add to the list: «6. **Ergänzungen:** Teile mit `herkunft: "ergaenzt"` stammen nicht aus dem Buch. Prüfe sie besonders streng und korrigiere, was der Zusammenfassung widerspricht.» Do not let the check change `herkunft` (add to the «nie ändern» sentence).

**Tests (first):**
- photos + prompt: analysis prompt contains «Die Fotos sind der Rahmen» and «Auftrag der Eltern: Prüfung am Freitag».
- prompt only: analysis prompt contains «keine Fotos», request has no images.
- the Auftrag is in the prompt of `module`, `pruefung` and `grafik` requests.
- `ergaenzungen` from the analysis (push `analysis(['ergaenzungen' => ['Zellatmung ergänzt.']])`) end up in `$lesson->additions` and in the `module` prompt.
- a legacy lesson with `notes` still sends «Hinweise der Eltern» on retry (factory lesson with notes, status Failed, images present or topic set).

**Commit:** «Prompts: Fotos als Rahmen, Auftrag und Ergänzungen in allen Schritten»

---

### Task 5: What the views receive

**Files:**
- Modify: `app/Lessons/LessonView.php`
- Modify: `app/Http/Controllers/LessonController.php` (`render`)
- Modify: `app/Http/Controllers/LessonContentController.php` (`edit`)
- Test: `tests/Feature/Lessons/LessonPageTest.php`, `tests/Feature/Lessons/ProgressTest.php` or a new `tests/Feature/Lessons/OriginTest.php`

**LessonView::page(Lesson $lesson, bool $showOrigin = false)**: when `! $showOrigin || $lesson->isFromTopic()`, return `content` with every `herkunft` key removed (recursive helper `withoutOrigin(array): array` that unsets `herkunft` at any depth). Shared view calls it as today (default false). `LessonController::render()` passes `showOrigin: $parent`. Add to the parent props: `'additions' => $lesson->isFromTopic() ? [] : ($lesson->additions ?? [])`.

`LessonContentController::edit()` sends content as stored (with `herkunft`) plus `'showOrigin' => ! $lesson->isFromTopic()`. `update()` must keep accepting and storing `herkunft` (validator allows it). The cloze is edited as markup and re-parsed: carry over the old `module.lueckentext.herkunft` into the re-parsed cloze.

**Tests (first):**
- parent view of a photo lesson with an `ergaenzt` block contains `herkunft` and `parent.additions`.
- shared view of the same lesson contains no `herkunft` anywhere (`json_encode` of the prop must not contain `herkunft`).
- parent view of a prompt-only lesson contains no `herkunft` and empty `additions`.
- editing the cloze keeps its `herkunft`.

**Commit:** «Herkunft nur für Eltern und nur bei Lernseiten mit Fotos»

---

### Task 6: Upload form

**Files:**
- Modify: `resources/js/pages/lessons/Create.vue`

Changes:
- Remove `source`, `sources`, `topic`, `notes` from the form and template; add `prompt: ''`.
- Photos section always visible, label «Fotos (optional)», hint: «Die Fotos geben den Rahmen vor: Stoff, Begriffe, Niveau. Gut lesbar, gerade von oben, ganze Seite im Bild. Sie werden nach der Erstellung gelöscht.»
- New field «Auftrag (optional)» after the photos: textarea `id="prompt"`, `rows="4"`, `maxlength="1000"`, placeholder «z. B. Prüfung am Freitag, vor allem die Begriffe auf Seite 2. Auch die Zellatmung, die kommt auch dran.», a live counter `{{ form.prompt.length }}/1000`, hint «Ohne Fotos schreibt die KI aus ihrem Fachwissen. Mit Fotos bleibt sie beim Stoff der Fotos und ergänzt nur, was fehlt. Ergänzungen sind für dich markiert. Bitte keine Namen oder persönlichen Angaben.», `<InputError :message="form.errors.prompt" />`.
- Chips above the textarea (buttons, `type="button"`, small rounded border, same look as the source toggle's inactive state): «Prüfung am …», «Nur die Fachbegriffe», «Mit Beispielen aus dem Alltag», «Auch das Thema … ergänzen». Clicking appends the text to `form.prompt` (with «. » separator if the prompt doesn't end in punctuation or is non-empty) and focuses the textarea; placeholders `…` stay for the parent to fill.
- `hasSource`: `form.images.length > 0 || form.prompt.trim() !== ''`.
- `submit()` transform: drop the `source`/`topic` logic, keep the child logic.
- Heading description: «Aus Fotos vom Schulbuch, einem Auftrag oder beidem entsteht eine Lernseite mit Grafik, Quiz und Übungen.»
- Show `imageErrors()` and `form.errors.images` as today.

Verify: `npm run types:check`, `npm run check`. Then with `LESSON_FAKE_AI=true` and `php artisan queue:work --once` style or `QUEUE_CONNECTION=sync`, open https://tutor.kimiyu.ch.test/lernseiten/neu in the browser (claude-in-chrome) and create one lesson with prompt only and one with photo + prompt; confirm both land on the lesson page. Don't commit `.env` changes.

**Commit:** «Formular: Fotos und Auftrag»

---

### Task 7: Markers for parents

**Files:**
- Create: `resources/js/components/lesson/OriginBadge.vue`
- Modify: `resources/js/components/lesson/LessonBlock.vue`, `QuizModule.vue`, `SortModule.vue`, `FlashcardModule.vue`, `ClozeModule.vue`
- Modify: `resources/js/components/lesson/ReviewNotice.vue`, `resources/js/pages/lessons/Show.vue`
- Modify: `resources/js/pages/lessons/Edit.vue`

**OriginBadge:** props `origin?: 'foto' | 'ergaenzt'`; renders nothing unless `ergaenzt`; else a small inline pill «ergänzt» with `title="Nicht auf den Fotos, von der KI aus Fachwissen ergänzt"`, using the lesson tokens (`text-ls-muted`, `border-ls-line`, rounded-full, `text-xs`). Because the child's view never receives `herkunft`, components can render the badge unconditionally.

**Placement:** top-right of each block in `LessonBlock` (wrap in a relative container only when the badge shows, or render it before the block content as a line of its own — pick whatever keeps the existing layout unchanged when there is no badge); next to the question in `QuizModule`; on each sort term chip in `SortModule`; on the card front in `FlashcardModule`; next to the cloze module heading in `ClozeModule`.

**ReviewNotice:** new prop `additions: string[]`; when non-empty, show under the review text: «**{n} Teile stammen nicht aus den Fotos.** Die KI hat sie aus Fachwissen ergänzt, sie sind mit «ergänzt» markiert. Bitte besonders genau prüfen.» plus a `<details>` list of the additions (same style as the check notes). `Show.vue` passes `parent.additions` (add to the props type).

**Edit.vue:** show `OriginBadge` next to blocks, questions, terms and cards (only when `showOrigin`, new prop). Add a «Baustein entfernen» button per block in a section (same style as «Frage entfernen»), disabled when the section has only one block; server validation stays the backstop.

Verify: `npm run types:check`, `npm run check`, `php artisan test --compact`. In the browser (fake AI): push no special responses; instead temporarily set one block's `herkunft` to `ergaenzt` via tinker on a local lesson and check parent view (badge + banner) vs. the child's link (no badge).

**Commit:** «Ergänzte Teile für Eltern markieren»

---

### Task 8: Docs

- Design doc «Teil 1»: note the decisions taken in implementation (`photo_count`, `additions`, `displayTitle`, legacy `topic`/`notes` kept).
- `docs/deployment.md`: add «Migration ausführen» note if the deploy steps don't already run `php artisan migrate --force` (check `deploy.sh`).

**Commit:** «Docs: Teil 1»

---

### Task 9: Real API check (manual, costs money – ask first)

1. `LESSON_FAKE_AI=false`, queue worker running.
2. Create three lessons: photos only, prompt only, photos + prompt naming a topic not on the photos.
3. Confirm: no «compiled grammar too large» error; the third lesson has `additions`, `ergaenzt` badges where expected, banner shows; child link shows no badges.
4. Note results and costs in the design doc.
