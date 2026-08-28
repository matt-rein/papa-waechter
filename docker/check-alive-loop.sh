#!/bin/sh
set -e

# Einfacher, container-freundlicher "Cron": ruft in festem Intervall die
# Lebenszeichen-Prüfung auf. Standard: alle 900 Sekunden (15 Minuten).
INTERVAL="${CHECK_INTERVAL:-900}"

# Nach jedem *erfolgreichen* Durchlauf wird diese Datei angefasst. Der
# Healthcheck liest ihr Alter – so fällt auch ein Dienst auf, der zwar noch
# läuft, dessen Prüfung aber dauerhaft scheitert.
BEAT="${CRON_HEARTBEAT:-/app/var/cron-heartbeat}"

echo "[cron] Lebenszeichen-Prüfung alle ${INTERVAL}s"
while true; do
    if php /app/bin/console app:check-alive --no-interaction; then
        touch "$BEAT"
    else
        echo "[cron] Prüfung fehlgeschlagen (wird beim nächsten Lauf erneut versucht)"
    fi
    sleep "${INTERVAL}"
done
