# Teil 6 – Aussprache mit ElevenLabs: Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Foreign words in language lessons are read aloud with a natural ElevenLabs voice, the same on every device, without installing system voices. The browser voice (`SpeakButton.vue` today) stays as the fallback.

**Budget:** Marcel has 10'000 ElevenLabs credits. A credit is roughly one character (`eleven_multilingual_v2`), half a credit on the Flash/Turbo models. A vocabulary lesson has 300–500 characters → 20–30 lessons on v2, 40–60 on Flash. Every clip is generated once and reused across lessons, so playback costs nothing.

**Check before starting:** the free plan has historically not allowed commercial use and required attribution. Fine for family use; check ElevenLabs' current terms before the app goes beyond that.

**Architecture:**
- **What is spoken:** exactly what has a speaker button today: `vocabulary` block entries (`foreign`) and flashcard fronts (`modules.flashcards.entries[].front`), only in a `languages` lesson with a `speechLang` (`Profile::speechLang()`).
- **Spoken text** is computed in PHP (`App\Lessons\Speech\Texts::spokenText()`): the part before the first «(» or «/», trimmed («parlé (parler)» → «parlé»). This is today's rule from `SpeakButton.vue`. It moves to PHP because the clip is keyed by it; the Vue component keeps its copy only for the browser fallback.
- **Clips are shared** across lessons and accounts: table `speech_clips`, unique `hash` = sha256 of `lang|voice_id|model|spoken text`. «le livre» is generated once. Changing voice or model in the config produces new clips, old ones stay valid for nothing and can be deleted by hand.
- **Storage:** private disk `speech` (`storage/app/private/speech`, file `{hash}.mp3`). Served by `GET audio/{hash}.mp3` (`SpeechClipController`, throttled, `Cache-Control: public, max-age=31536000, immutable`). No signed URL: the files contain single words, nothing personal, and the hash isn't guessable. No `storage:link` needed (Hostpoint).
- **Generation:** new job `SpeakLesson` (step `speech`) in `GenerationPipeline::write()` after `CheckLesson`, before the graphics. Action `App\Actions\Generation\SpeakLesson`:
	- collects the texts, skips those with a clip, calls ElevenLabs one by one (the free plan allows only 2 concurrent requests);
	- **never fails the lesson:** API errors are logged and end the step; missing clips simply fall back to the browser voice. On «quota exceeded» (HTTP 401 with `quota_exceeded`, verify in Task 1) it stops at once;
	- stops at `config('speech.max_characters_per_lesson')` (default 1500) as a guard against runaway content;
	- does nothing when `ELEVENLABS_API_KEY` is empty or there is no voice for the language.
- **After edits:** `UpdateLessonContent` dispatches `SpeakLesson` when the lesson has a `speechLang` (new or changed words get their clip; existing ones are skipped, so it is cheap).
- **Existing lessons:** `php artisan lessons:speak {lesson?}` runs the action for one lesson or all `languages` lessons (e.g. lesson 11).
- **Frontend:** `LessonView::page()` adds `speechClips`: a map from the original text (`entry.foreign`, `card.front`) to the clip URL. `SpeakButton` gets an optional `src`: if set, it plays the file with `new Audio(src)` (and shows the button even without a browser voice); otherwise it uses the browser voice as today. If playback fails (`play()` rejects or `error` event), it falls back to the browser voice.
- **Costs:** each ElevenLabs call is logged in `generations` with step `speech`, model = ElevenLabs model id, a new nullable column `credits`, and `cost_usd` = credits × `config('speech.price_per_1000_characters') / 1000` (default 0 on the free plan). The Kosten page shows credits used this month next to the USD sums, so Marcel can compare with the remaining credits.

**ElevenLabs API** (checked against the docs 2026-10-03):
- `POST https://api.elevenlabs.io/v1/text-to-speech/{voice_id}?output_format=mp3_44100_64`, header `xi-api-key: {key}`, JSON body `{"text": "...", "model_id": "..."}`. The response is the MP3 bytes.
- `language_code` is **not supported by `eleven_multilingual_v2`** (the docs say so). Send it only for models that accept it (`config('speech.language_code_models')`); with v2 a short word like «chat» may come out English, which Task 1 checks by listening.
- **Models** (API price per 1000 characters, October 2026): `eleven_v4` $0.022 (promotion until Oct 12, then about $0.08), `eleven_v4_turbo` $0.011, `eleven_multilingual_v2` $0.08, `eleven_flash_v2_5` $0.04 (half the credits of v2). Docs recommend `eleven_v4` or `eleven_multilingual_v2` for quality. The credits per character for v4 are not documented; measure them in Task 1.
- **No cost header:** the docs name none. Count `mb_strlen($text)` × the model's credit factor (`config('speech.credits_per_character')`).
- **Quota exhausted:** 401 or 429 with `detail.status` / `error_code` `quota_exceeded` (reports differ). Treat both as «quota exceeded» and stop. Quota resets on the 1st of the month (UTC).
- Remaining credits: `GET /v1/user/subscription` (`character_count`, `character_limit`); used by the backfill command to print what is left.
- Called with Laravel's `Http` client (`Http::fake()` in tests, never the real API).

**Tech Stack:** Laravel 13, Pest 4, Inertia 3 + Vue 3. No new Composer or npm packages.

**Conventions:** as in `CLAUDE.md`:
- English keys, identifiers and comments; German only for UI text (Swiss spelling, no «ß», quotes «…»);
- tabs;
- slim controllers; logic in actions with one `handle()`, props in page-data classes;
- Pest TDD; `Http::fake()` for ElevenLabs;
- back up `database/database.sqlite` and test migrations on a copy in the scratchpad first;
- `php artisan queue:restart` after code changes; `npm run build` and commit `public/build` with frontend changes.

---

### Task 1: Config, API check and client

**Files:**
- New `config/speech.php`:
	- `key` ← `ELEVENLABS_API_KEY` (null disables everything);
	- `model` ← `ELEVENLABS_MODEL` (default `eleven_multilingual_v2`);
	- `voices` per language base: `fr` ← `ELEVENLABS_VOICE_FR`, `en` ← `ELEVENLABS_VOICE_EN`, `it` ← `ELEVENLABS_VOICE_IT` (no defaults; Marcel picks voices in the ElevenLabs voice library);
	- `max_characters_per_lesson` (1500), `price_per_1000_characters` (0.0).
- `.env.example`: the new variables, empty.
- New `app/Lessons/Speech/ElevenLabs.php`: `synthesize(string $text, string $lang, string $voiceId): SpeechResult` (bytes + credits). Throws `SpeechFailed` with a `quotaExceeded` flag.
- Tests: `tests/Unit/Lessons/ElevenLabsTest.php` with `Http::fake()`: request URL, header, body; credits = characters × the model's factor; quota error → `quotaExceeded`; other error → `SpeechFailed`.

Steps:
1. Read the current ElevenLabs API docs (text-to-speech endpoint, `language_code` support per model, cost header, quota error shape, rate limits). Correct this plan where it differs.
2. Manual check with Marcel's key: one `curl` for «le livre», listen to it, note the credits used. Pick the French voice together with Marcel.
3. TDD the client, commit.

### Task 2: Clips table, spoken text, storage

**Files:**
- New migration `create_speech_clips_table`: `id`, `hash` (unique), `lang`, `voice_id`, `model`, `text`, `credits`, `timestamps`.
- New migration `add_credits_to_generations_table`: `credits` unsigned int nullable (ElevenLabs bills credits, about one per character).
- New model `App\Models\SpeechClip` (`path()` → `{hash}.mp3`; `url()` comes with the route in Task 4).
- `config/filesystems.php`: disk `speech` (private, `storage/app/private/speech`, `serve` false).
- New `app/Lessons/Speech/Texts.php`: `spokenText(string $text): string`, `texts(array $content): list<string>` (vocabulary entries + flashcard fronts, unique, original texts), `hash(string $spoken, string $lang, string $voiceId, string $model): string`.
- Tests: `tests/Unit/Lessons/SpeechTextsTest.php` (spoken text rule with the examples from `SpeakButton.vue`; texts from a fixture lesson of the `languages` profile; hash changes with voice/model).

Steps: back up the DB, test both migrations on a copy, migrate. TDD `Texts`, commit.

### Task 3: `SpeakLesson` action and job

**Files:**
- New `app/Actions/Generation/SpeakLesson.php` `handle(Lesson $lesson): void`, as under Architecture. One `generations` row per API call (status `ok`/`failed`, `credits`, `cost_usd`, `duration_ms`, `error`).
- New `app/Jobs/SpeakLesson.php` extends `LessonStep`, step `speech`. The action catches its own errors, so the chain always continues.
- `GenerationPipeline::write()`: `SpeakLesson` after `CheckLesson`, only if the lesson has a `speechLang` and `config('speech.key')` is set.
- `resources/js/components/lesson/GenerationStatus.vue`: step `speech` («Aussprache aufnehmen»).
- Tests: `tests/Feature/Lessons/SpeakLessonTest.php` with `Http::fake()` and `Storage::fake('speech')`:
	- creates one clip per unique spoken text and logs one generation each;
	- reuses existing clips (no request);
	- skips non-language lessons and lessons without a voice or key;
	- stops at the character limit;
	- stops after a quota error, keeps the clips made so far, lesson still reaches `review`;
	- a deleted lesson does nothing (`LessonStep` guard).
	- Pipeline test (`Bus::fake()`): `SpeakLesson` sits between `CheckLesson` and the graphics, only for language lessons.

Steps: tests first, implement, full suite + phpstan, commit.

### Task 4: Serving and props

**Files:**
- New `app/Http/Controllers/SpeechClipController.php` (invokable): find by hash or 404, stream the file from the `speech` disk with `audio/mpeg` and the immutable cache header.
- `routes/web.php`: `Route::get('audio/{hash}.mp3', SpeechClipController::class)->where('hash', '[0-9a-f]{64}')->middleware('throttle:120,1')->name('speech.clip')`, outside the auth group (children use the shared link without login).
- `app/Lessons/LessonView.php`: `speechClips` = map original text → clip URL, for the texts of `Texts::texts()` that have a clip with the current voice/model. Empty map when off.
- Tests: controller (200 with headers, 404 for an unknown hash, works without login); `LessonView` props for a language lesson with and without clips; shared child view gets the same map.

Steps: TDD, commit.

### Task 5: Frontend

**Files:**
- `resources/js/components/lesson/SpeakButton.vue`: optional prop `src`. With `src`: show the button always, play via `Audio` (one shared element per page so a new word stops the old one), on failure use the browser voice. Without `src`: unchanged.
- `LessonBlock.vue`, `FlashcardModule.vue`, and whatever passes `speechLang` down (`Show.vue`, the shared page): pass `speechClips` and set `:src="speechClips?.[entry.foreign]"` / `card.front`.
- `resources/js/types`: type for `speechClips` (`Record<string, string>`).

Steps: implement, `npm run types:check`, `npm run check`, `npm run build`, check in the browser on lesson 11 after Task 6 (with clips, and with the key removed → browser voice). Commit with `public/build`.

### Task 6: Backfill command, edits, Kosten page

**Files:**
- New `app/Console/Commands/SpeakLessons.php` (`lessons:speak {lesson?}`): runs `SpeakLesson` synchronously, prints clips created, reused, credits used and credits left.
- `app/Actions/Lessons/UpdateLessonContent.php`: after saving, dispatch the `SpeakLesson` job for a language lesson (only if the key is set).
- `app/Http/PageData/CostOverview.php` + `resources/js/pages/Costs.vue` (or the current Kosten page): credits per month for step `speech`, labelled «Aussprache (ElevenLabs): N Credits».
- Tests: command (one lesson, all lessons); edit dispatches the job (`Bus::fake()`); cost props include the credits.

Steps: TDD, full checks (`composer format:check`, tests, phpstan, `npm run types:check`, `npm run check`), commit. Run `php artisan lessons:speak 11` with the real key and check the credits used on the ElevenLabs dashboard against the Kosten page.

### Task 7: Docs

- `docs/deployment.md`: new env variables, `storage/app/private/speech` must be writable, `php artisan lessons:speak` once after deploy.
- `docs/plans/STATUS.md`: Teil 6 done, decisions (voice, model), what Marcel has to test.

### Task 8: Real check (manual, by Marcel)

- New French lesson: words and flashcards play the ElevenLabs voice, also on the phone via the child link.
- Edit a word: after saving, the new word plays the new clip within a minute (queue worker running).
- Credits on the ElevenLabs dashboard match the Kosten page.

## Decisions (Task 1, 2026-10-03)

- **Free plan: no library voices via the API** («Free users cannot use library voices via the API», HTTP 402), even when added to the account (tried «Dorian», «Anaïs»). Only the premade voices work.
- **Voice «Alice»** (`Xb7hH8MSUJpSbSDYk0k2`, premade, British, speaks French), **model `eleven_v4`** with `language_code: fr`. Picked by Marcel from 12 test clips (4 premade voices × v4 / multilingual v2 / Flash v2.5).
- **Credits measured:** 12 clips of 22 characters used 220 credits → v4 and multilingual v2 1 credit per character, Flash v2.5 0.5.
- A native voice later (paid plan) only needs `ELEVENLABS_VOICE_FR`; the hash includes the voice, so all words are generated again.
- Task 2 names the text helper `App\Lessons\Speech\Texts` (next to `ElevenLabs`, `SpeechResult`, `SpeechFailed` in `app/Lessons/Speech/`) instead of `App\Lessons\Speech`.

## As built (Task 3, 2026-10-03)

- `Lesson::speechLang()` and `Lesson::speaksWithElevenLabs()` (language lesson + key + voice for the language). The pipeline adds `SpeakLesson` only when the latter is true (in `write()` and in `start()` with finished content, via `finishingJobs()`), so other lessons' chains are unchanged.
- The action stops at the **first** error of any kind, not only on quota: a wrong key, a library voice on the free plan or a missing voice would fail for every word. Failed calls are logged with status `error` and 0 credits.
- A clip whose file is missing is generated again (`updateOrCreate` on the hash).
- Task 4: `Lesson::speechVoice()` holds the voice lookup (used by `speaksWithElevenLabs()`, the action and `LessonView`). A clip row without its file answers 404, so the button falls back to the browser voice.
- The progress display gets `lesson.speaks` and shows «Aussprache aufnehmen» only then.

## As built (Tasks 5–7, 2026-10-03)

- Task 5: `resources/js/lib/speech.ts` holds the one page-wide player; an interrupted play (`AbortError`, the next word was tapped) does not fall back to the browser voice.
- Task 6: after an edit, `UpdateLessonContent` dispatches a queued closure instead of the `SpeakLesson` job: a `LessonStep` would set `step` on a finished page. The command prints the credits left from `GET /v1/user/subscription` (ElevenLabs' counter lags a few minutes). The Kosten page shows credits per month and per step.
- First real run: lesson 11, 41 clips, 475 credits.

## Open questions
- **Conjugation tables:** not spoken today. Add later if wanted (`conjugation.forms[].form`, about 6 clips per verb).
