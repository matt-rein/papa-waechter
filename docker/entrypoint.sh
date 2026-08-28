#!/bin/sh
set -e

# Volumes sind beim ersten Start leer und verdecken die im Image angelegten
# Ordner – deshalb hier noch einmal anlegen.
mkdir -p var/cache var/log var/photo-cache data photos
chown -R www-data:www-data var data photos 2>/dev/null || true

# Wird ohne Argumente gestartet (Standard-CMD), kümmern wir uns um Migrationen
# und starten dann den FrankenPHP-Server. Mit Argumenten (z. B. der Cron-Loop)
# wird einfach der übergebene Befehl ausgeführt.
if [ "$#" -eq 0 ]; then
    echo "[entrypoint] Datenbank-Migrationen ausführen ..."
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || true

    echo "[entrypoint] FrankenPHP starten ..."
    exec frankenphp run --config /etc/frankenphp/Caddyfile
fi

exec "$@"
