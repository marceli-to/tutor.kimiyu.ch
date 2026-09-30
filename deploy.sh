#!/usr/bin/env bash
#
# Deploy auf Hostpoint: lokal bauen und committen, auf dem Server git pull.
#
#   ./deploy.sh
#
# Einstellungen in .deploy (nicht im Git):
#   DEPLOY_HOST=benutzer@server.hostpoint.ch
#   DEPLOY_PATH=www/tutor.kimiyu.ch
#   DEPLOY_PHP=php          # z. B. php83, falls die Standardversion älter ist
#
set -euo pipefail
cd "$(dirname "$0")"

[ -f .deploy ] && source .deploy
: "${DEPLOY_HOST:?DEPLOY_HOST fehlt (in .deploy setzen)}"
: "${DEPLOY_PATH:?DEPLOY_PATH fehlt (in .deploy setzen)}"
PHP="${DEPLOY_PHP:-php}"

echo "→ Assets bauen"
npm run build >/dev/null

if [ -n "$(git status --porcelain)" ]; then
    echo "Es gibt nicht committete Änderungen (oder der Build hat sich geändert):"
    git status --short
    echo "Bitte zuerst committen, dann nochmals ./deploy.sh"
    exit 1
fi

echo "→ Push nach GitHub"
git push --quiet

echo "→ Server aktualisieren"
ssh "$DEPLOY_HOST" bash -s <<REMOTE
set -euo pipefail
cd "$DEPLOY_PATH"
git pull --ff-only --quiet
if command -v composer >/dev/null; then
    composer install --no-dev --optimize-autoloader --no-interaction --quiet
else
    $PHP composer.phar install --no-dev --optimize-autoloader --no-interaction --quiet
fi
$PHP artisan migrate --force
$PHP artisan optimize
# Der Cron-Worker beendet sich ohnehin nach 50 s; so übernimmt er sicher den neuen Code
$PHP artisan queue:restart
echo "Deploy fertig: \$(git log --oneline -1)"
REMOTE
