# tutor.kimiyu.ch

Laravel 13 + Inertia 3 + Vue 3 + Tailwind 4 app that turns photos of textbook pages and/or a parent's prompt («Auftrag») into interactive lesson pages for Swiss secondary-school pupils, generated with the Claude API.

## Project status

`docs/plans/STATUS.md` is the single source for what's done, what's open, decisions and things the user must test. It is loaded automatically at session start.

- **After every completed task** (a commit that finishes a plan task, a fix the user asked for, a decision the user made), update `docs/plans/STATUS.md` in the same or the next commit. Keep it current, short and structured as described in `.claude/skills/handover/SKILL.md`.
- Before switching sessions the user may run `/handover`.
- Detailed plans live in `docs/plans/*.md`; the overall design in `docs/plans/2026-10-02-neue-lernseite-design.md`.

## Conventions

- **English** for identifiers, array/JSON keys (including stored lesson content and the Claude structured-output schemas), enum values and code comments. **German** (Swiss spelling, no «ß», quotes «…») only for UI text, validation messages, prompt prose in `resources/prompts/`, and URL paths.
- **Tabs** for PHP, Vue, TS, CSS, Blade; spaces for JSON, YAML, Markdown. Format with `composer format` (PHP-CS-Fixer, Laravel rules) and `npm run check:fix`.
- **Slim controllers:** authorize, call one action, redirect/render. Write logic in `app/Actions/{Area}/` (one public `handle()`), Inertia props in `app/Http/PageData/*::props()`. Generation steps are actions in `app/Actions/Generation/`, called by jobs.
- No trivial wrapper helpers; don't re-implement framework behaviour (TrimStrings/ConvertEmptyStringsToNull are active).
- Tests: Pest, TDD for PHP. Use `FakeLanguageModel` — tests never call the real API.

## Checks before committing

`composer format:check`, `php artisan test --compact`, `vendor/bin/phpstan analyse --memory-limit=1G`, `npm run types:check`, `npm run check`. Never commit `public/build`.

## Pitfalls

- The Claude API rejects structured-output schemas that compile too large; byte size does not predict it. After changing any schema or model, run `php artisan lessons:check-schemas` (real API, < $0.01).
- Restart the queue worker (`php artisan queue:restart`) after code changes, or jobs keep running old code.
- `database/database.sqlite` is a copy of production data: test migrations on a copy first and back it up before migrating.
