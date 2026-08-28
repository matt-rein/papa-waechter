#!/bin/sh
#
# Lebenszeichen-Prüfung für klassische Cron-Dienste (DomainFactory), die nur
# Shell-Skripte starten können und keine Befehle mit Argumenten.
#
# Eintrag im Kundenmenü, alle 15 Minuten:
#   /kunden/135359_61348/watcher/bin/cron-check-alive.sh
#
# Verhalten: Bei Erfolg still (Ausgabe nur ins Logfile), im Fehlerfall Ausgabe
# auf stderr – daraus macht der Cron-Dienst eine E-Mail an dich.
#
# Einstellbar über .env.local auf dem Server (wird vom Deploy nicht angefasst):
#   CRON_PHP_BIN=/usr/bin/php84
#   HEALTHCHECK_PING_URL=https://hc-ping.com/<uuid>

set -u

# Projektverzeichnis aus dem eigenen Pfad ableiten – der Cron startet
# irgendwo, meist im Home.
SCRIPT_DIR=$(cd -- "$(dirname -- "$0")" && pwd) || exit 1
PROJECT_DIR=$(dirname -- "$SCRIPT_DIR")
cd "$PROJECT_DIR" || exit 1

LOG="$PROJECT_DIR/var/log/check-alive.log"
mkdir -p "$PROJECT_DIR/var/log"

# Einen Wert aus .env.local lesen (ohne die Datei auszuführen).
read_env() {
    [ -f .env.local ] || return 0
    sed -n "s/^$1=//p" .env.local | head -1 | sed 's/^["'"'"']//; s/["'"'"']$//'
}

PHP_BIN=$(read_env CRON_PHP_BIN)
if [ -z "$PHP_BIN" ]; then
    for candidate in /usr/bin/php84 /usr/local/bin/php84 php84 php8.4 php; do
        if command -v "$candidate" >/dev/null 2>&1; then
            PHP_BIN="$candidate"
            break
        fi
    done
fi

if [ -z "$PHP_BIN" ]; then
    echo "[check-alive] Kein PHP gefunden. CRON_PHP_BIN in .env.local setzen." >&2
    exit 1
fi

# Die Anwendung braucht mindestens PHP 8.4; das nackte "php" ist am df.eu 7.4
# und würde mit einer schwer lesbaren Meldung scheitern.
if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' 2>/dev/null; then
    echo "[check-alive] $PHP_BIN ist zu alt ($("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null))." >&2
    echo "[check-alive] Es wird PHP 8.4 oder neuer gebraucht – CRON_PHP_BIN in .env.local setzen." >&2
    exit 1
fi

STAMP=$(date '+%Y-%m-%d %H:%M:%S')
OUTPUT=$("$PHP_BIN" bin/console app:check-alive --no-interaction 2>&1)
STATUS=$?

printf '%s [%s]\n%s\n\n' "$STAMP" "$([ $STATUS -eq 0 ] && echo OK || echo FEHLER)" "$OUTPUT" >> "$LOG"

# Logfile nicht unbegrenzt wachsen lassen.
if [ -f "$LOG" ] && [ "$(wc -l < "$LOG")" -gt 5000 ]; then
    tail -n 2000 "$LOG" > "$LOG.tmp" && mv "$LOG.tmp" "$LOG"
fi

if [ $STATUS -ne 0 ]; then
    echo "[check-alive] Prüfung fehlgeschlagen (Exit $STATUS):" >&2
    echo "$OUTPUT" >&2
    exit $STATUS
fi

# Wächter für den Wächter: nur nach erfolgreichem Lauf melden. Bleibt der Ping
# aus, schlägt der externe Dienst Alarm – sonst bemerkt niemand, dass hier
# nichts mehr läuft.
PING_URL=$(read_env HEALTHCHECK_PING_URL)
if [ -n "$PING_URL" ]; then
    curl -fsS -m 10 "$PING_URL" >/dev/null 2>&1 || \
        echo "[check-alive] Ping an $PING_URL fehlgeschlagen." >&2
fi

exit 0
