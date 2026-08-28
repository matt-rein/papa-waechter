#!/bin/sh
#
# Healthcheck für den Cron-Dienst.
#
# Das Basis-Image bringt einen Healthcheck für den Webserver mit – den startet
# dieser Container aber gar nicht, er stünde also dauerhaft auf "unhealthy".
# Stattdessen prüfen wir hier, ob die Lebenszeichen-Prüfung kürzlich
# durchgelaufen ist.

INTERVAL="${CHECK_INTERVAL:-900}"
BEAT="${CRON_HEARTBEAT:-/app/var/cron-heartbeat}"

# Zwei Durchläufe Karenz plus etwas Luft für einen langsamen Lauf.
MAX_AGE=$((INTERVAL * 2 + 60))

if [ ! -f "$BEAT" ]; then
    echo "Noch kein erfolgreicher Durchlauf ($BEAT fehlt)."
    exit 1
fi

LAST=$(stat -c %Y "$BEAT" 2>/dev/null) || LAST=""
if [ -z "$LAST" ]; then
    echo "Zeitstempel von $BEAT nicht lesbar."
    exit 1
fi

AGE=$(( $(date +%s) - LAST ))
if [ "$AGE" -gt "$MAX_AGE" ]; then
    echo "Letzter erfolgreicher Durchlauf vor ${AGE}s (erlaubt: ${MAX_AGE}s)."
    exit 1
fi

echo "Letzter erfolgreicher Durchlauf vor ${AGE}s."
exit 0
