# Deployment auf Hostpoint

Die App läuft auf Hostpoint (Shared Hosting) unter `/home/pumutuxu/www/tutor.kimiyu.ch`, PHP 8.4, SQLite.

## Laufender Betrieb

Neue Version ausliefern, im Projektordner auf dem Mac:

```bash
./deploy.sh
```

Das Skript baut die Assets, bricht ab, wenn etwas nicht committet ist, pusht nach GitHub und führt auf dem Server aus: `git pull`, `composer install --no-dev`, `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart`.

Host und Pfad stehen in `.deploy` (nicht im Git):

```
DEPLOY_HOST=pumutuxu@<ssh-host>
DEPLOY_PATH=/home/pumutuxu/www/tutor.kimiyu.ch
```

Ohne Skript, von Hand auf dem Server:

```bash
cd /home/pumutuxu/www/tutor.kimiyu.ch
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

Die gebauten Assets (`public/build`) sind im Git, weil es auf Hostpoint kein Node gibt. Vor jedem Commit mit Frontend-Änderungen also `npm run build`.

### Schemas prüfen

Nach einem Deploy, der Schemas (`App\Lessons\Ai\Schemas`, Fachprofile) oder Modelle (`LESSON_MODEL_*`) ändert, auf dem Server:

```bash
php artisan lessons:check-schemas
```

Der Befehl schickt jedes Schema, das die Prompts verwenden, mit dem Modell des jeweiligen Schritts und einem einzigen Ausgabe-Token an die API (kostet zusammen weniger als $0.01). Die API lehnt zu grosse Grammatiken ab («grammar is too large»), und die Grösse in Bytes sagt das nicht zuverlässig voraus. Jede Zeile muss `OK` zeigen; sonst endet der Befehl mit Exit-Code 1 und das betroffene Fachprofil braucht weniger Bausteine.

### Aussprache (ElevenLabs)

Fremdsprachige Wörter (Wortlisten, Vorderseite der Karteikarten) liest eine ElevenLabs-Stimme vor. Jedes Wort wird einmal erzeugt und von allen Lernseiten geteilt (Tabelle `speech_clips`, Dateien in `storage/app/private/speech`, muss beschreibbar sein). Ohne `ELEVENLABS_API_KEY` oder ohne Clip liest die Stimme des Browsers.

Nach dem ersten Deploy mit Aussprache, und wenn Stimme oder Modell wechseln, die bestehenden Lernseiten nachholen:

```bash
php artisan lessons:speak        # alle Sprach-Lernseiten
php artisan lessons:speak 11     # eine Lernseite
```

Der Befehl zeigt neue und wiederverwendete Clips, die verbrauchten Credits und die übrigen Credits des Monats (der Zähler von ElevenLabs hinkt einige Minuten nach). Der Gratis-Plan hat 10'000 Credits pro Monat (etwa 1 Credit pro Zeichen mit `eleven_v4`) und kann über die API nur die vorgefertigten Stimmen verwenden.

## Queue

Hostpoint hat keinen dauerhaften Worker. Ein Cronjob im Hostpoint-Panel startet jede Minute den Scheduler:

```
* * * * * cd /home/pumutuxu/www/tutor.kimiyu.ch && php artisan schedule:run >> /dev/null 2>&1
```

Der Scheduler startet `queue:work --stop-when-empty --max-time=50` (siehe `routes/console.php`). Eine neue Lernseite wartet deshalb bis zu einer Minute, bis sie startet.

Hängt eine Lernseite bei «Wartet auf den Start», läuft der Cronjob nicht: Pfad im Panel prüfen und `php artisan schedule:run` von Hand ausführen.

## Konten

Die Registrierung ist aus. Ein Konto auf dem Server anlegen:

```bash
php artisan users:create
```

Der Befehl fragt nach Name, E-Mail und Passwort (gleiche Regeln wie im Formular). Leer lassen erzeugt ein Passwort und zeigt es einmal an. Die E-Mail gilt als bestätigt.

## Konfiguration (.env auf dem Server)

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tutor.kimiyu.ch`
- `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `ANTHROPIC_EFFORT`
- `LESSON_FAKE_AI=false`, `LESSON_CHECK_ENABLED`, `LESSON_DELETE_IMAGES`
- Optional pro Schritt: `LESSON_MODEL_ANALYSIS|MODULES|CHECK|GRAPHIC` und `LESSON_EFFORT_ANALYSIS|MODULES|CHECK|GRAPHIC` (Standard siehe `config/lessons.php`: Module und Prüfung auf Sonnet, Grafik mit Effort `medium`)
- `ELEVENLABS_API_KEY` (Rechte: Text to Speech, Voices lesen, User lesen); optional `ELEVENLABS_MODEL` (Standard `eleven_v4`), `ELEVENLABS_VOICE_FR|EN|IT` (Standard Französisch: «Alice»), `ELEVENLABS_PRICE_PER_1000_CHARACTERS` (Standard 0, Gratis-Plan)
- `MAIL_MAILER=log` (keine E-Mails, Passwort-Reset funktioniert deshalb nicht)

## Logs und Fehler

- Laravel-Log: `storage/logs/laravel-*.log`
- Jeder API-Aufruf mit Tokens (Claude) oder Credits (ElevenLabs), Kosten und Fehlermeldung: Tabelle `generations`, in der App unter «Kosten»

## Sicherung

Die ganze Datenbank ist `database/database.sqlite`. Sichern:

```bash
scp pumutuxu@<ssh-host>:/home/pumutuxu/www/tutor.kimiyu.ch/database/database.sqlite ./backup-$(date +%F).sqlite
```
