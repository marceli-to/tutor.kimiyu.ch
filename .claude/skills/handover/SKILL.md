---
name: handover
description: Bring docs/plans/STATUS.md up to date, commit it and give the user a one-line prompt to continue in a fresh session. Use when the user types /handover or wants to switch to a new session.
---

# Handover

Goal: the next session can continue without this conversation. `docs/plans/STATUS.md` is loaded automatically at session start (SessionStart hook in `.claude/settings.json`), so it must be complete and current.

## Steps

1. **Wait for running work.** If subagents or background jobs started in this session are still running, say so and finish or stop them first — their results only reach this session.
2. **Collect facts, don't guess:**
	- `git log --oneline` since the last change to `docs/plans/STATUS.md` (`git log -1 --format=%H -- docs/plans/STATUS.md`), current branch, `git status --short` (ignore `public/build`, `package-lock.json` noise, but mention other uncommitted changes).
	- Plans in `docs/plans/` and their state; open items from this conversation; decisions the user made; anything the user still has to test or do (migrations, queue restart, deploy steps).
	- Test count and last known check results (`php artisan test --compact`, phpstan) — run them if unsure.
3. **Update `docs/plans/STATUS.md`** (English headings, German allowed for UI terms). Keep this structure:
	- **Stand** — date, branch, last commit, test count.
	- **Erledigt** — per part/plan: one line + key commits.
	- **Offen** — next steps in order, each pointing to its plan file/section.
	- **Entscheide** — durable decisions (architecture, conventions, product choices) with date.
	- **Für Marcel zu testen / zu tun** — browser checks, real API runs, migrations, deploy notes.
	- **Risiken und Stolpersteine** — e.g. API grammar size limit (run `php artisan lessons:check-schemas`), restart the queue worker after code changes, never migrate `database/database.sqlite` without a copy-test.
	- **So arbeiten wir** — short pointer list: `CLAUDE.md`, `composer format`, `npm run check:fix`, tests, actions/page data, English keys and comments, tabs.
	Remove items that are done; don't let the file grow into a changelog (git has that).
4. **Commit** only `docs/plans/STATUS.md` (and plan files you touched): message «Status aktualisiert» + the repo's Co-Authored-By trailer.
5. **Tell the user** in 3–6 lines what's next, and give the start line for the new session, e.g. «Weiter mit dem nächsten offenen Punkt aus STATUS.md».
