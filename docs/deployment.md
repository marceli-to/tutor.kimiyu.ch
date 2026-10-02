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
php artisan tinker --execute 'App\Models\User::create(["name" => "Name", "email" => "mail@beispiel.ch", "password" => "…", "email_verified_at" => now()]);'
```

## Konfiguration (.env auf dem Server)

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://tutor.kimiyu.ch`
- `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `ANTHROPIC_EFFORT`
- `LESSON_FAKE_AI=false`, `LESSON_CHECK_ENABLED`, `LESSON_DELETE_IMAGES`
- Optional pro Schritt: `LESSON_MODEL_ANALYSE|MODULE|PRUEFUNG|GRAFIK` und `LESSON_EFFORT_ANALYSE|MODULE|PRUEFUNG|GRAFIK` (Standard siehe `config/lessons.php`: Module und Prüfung auf Sonnet, Grafik mit Effort `medium`)
- `MAIL_MAILER=log` (keine E-Mails, Passwort-Reset funktioniert deshalb nicht)

## Logs und Fehler

- Laravel-Log: `storage/logs/laravel-*.log`
- Jeder API-Aufruf mit Tokens, Kosten und Fehlermeldung: Tabelle `generations`, in der App unter «Kosten»

## Sicherung

Die ganze Datenbank ist `database/database.sqlite`. Sichern:

```bash
scp pumutuxu@<ssh-host>:/home/pumutuxu/www/tutor.kimiyu.ch/database/database.sqlite ./backup-$(date +%F).sqlite
```
