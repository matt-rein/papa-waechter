#!/bin/sh
#
# Testhilfe für die lokale Entwicklung: verstellt das Alter der Check-ins,
# nicht die Fristen.
#
# Der Grund: wer zum Testen CHECKIN_WINDOW_HOURS herunterdreht, prüft
# anschließend eine Konfiguration, die es in Produktion nicht gibt. Hier
# bleiben die 6 Stunden Fenster und 26 Stunden Alarmschwelle unangetastet –
# stattdessen wandert die Historie nach hinten.
#
#   bin/dev-testdaten.sh neu          Historie neu setzen (7 Einträge)
#   bin/dev-testdaten.sh bestaetigt   jüngster vor 2 h  -> Seite zeigt den Haken
#   bin/dev-testdaten.sh knopf        jüngster vor 7 h  -> grüner Knopf ist zurück
#   bin/dev-testdaten.sh alarm        jüngster vor 27 h -> über der Schwelle
#   bin/dev-testdaten.sh zurueck 12   alles um 12 h zurückdatieren
#   bin/dev-testdaten.sh              nur den Stand anzeigen
#
# Nach "alarm" den Lauf auslösen, der sonst per Cron kommt:
#   php bin/console app:check-alive
#
# ACHTUNG: "neu" löscht alle Check-ins. Deshalb arbeitet das Skript
# ausschließlich auf data/data_dev.db und verweigert den Dienst, wenn die
# Datei fehlt – auf dem Server gibt es sie nicht.

set -u

SCRIPT_DIR=$(cd -- "$(dirname -- "$0")" && pwd) || exit 1
PROJECT_DIR=$(dirname -- "$SCRIPT_DIR")
cd "$PROJECT_DIR" || exit 1

# Fest verdrahtet auf die Entwicklungsdatenbank. Nicht konfigurierbar: ein
# Skript, das Lebenszeichen löscht, darf niemals auf data_prod.db zeigen
# können – auch nicht durch einen verrutschten Parameter.
DB="$PROJECT_DIR/data/data_dev.db"

if [ ! -f "$DB" ]; then
    echo "[testdaten] $DB gibt es nicht." >&2
    echo "[testdaten] Diese Hilfe ist nur für die lokale Entwicklung gedacht." >&2
    exit 1
fi

if ! command -v sqlite3 >/dev/null 2>&1; then
    echo "[testdaten] sqlite3 wird gebraucht, ist aber nicht installiert." >&2
    exit 1
fi

IPAD='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.6.1 Safari/605.1.15'

alter_h() {
    sqlite3 "$DB" "select round((julianday('now')-julianday(max(occurred_at)))*24,2) from check_in;"
}

# Schiebt die GESAMTE Historie so, dass der jüngste Eintrag das gewünschte
# Alter hat. Bewusst nicht eine einzelne Zeile: die kann dabei hinter die
# zweitjüngste rutschen, und dann ist unbemerkt ein anderer Eintrag "der
# letzte Check-in" – die Anzeige zeigt etwas anderes als eingestellt.
auf_alter() {
    sqlite3 "$DB" "update check_in set occurred_at = datetime(occurred_at,
        printf('%+d seconds',
            -( $1*3600 - (select (julianday('now')-julianday(max(occurred_at)))*86400
                          from check_in) )));"
}

leer_pruefen() {
    if [ "$(sqlite3 "$DB" 'select count(*) from check_in;')" -eq 0 ]; then
        echo "[testdaten] Keine Check-ins vorhanden – erst 'neu' aufrufen." >&2
        exit 1
    fi
}

case "${1:-stand}" in
    neu)
        sqlite3 "$DB" <<SQL
delete from check_in;
delete from sqlite_sequence where name='check_in';
-- Papas Muster aus der Produktion: ein Tipp pro Morgen, Abstände 22-27 h.
-- Die 27 h liegen über der Schwelle, damit das Protokoll auch eine
-- Alarm-Zeile zeigt. Die beiden jüngsten tragen die Geräte-Angabe des
-- Browsers ("iPad"), die älteren nicht ("Mac oder iPad") – so sind beide
-- Fälle nebeneinander zu sehen.
insert into check_in (watchee_id, occurred_at, ip_address, user_agent, device_hint)
select id, datetime('now','-145 hours'), '192.168.1.42', '$IPAD', null from watchee
union all select id, datetime('now','-121 hours'), '192.168.1.42', '$IPAD', null from watchee
union all select id, datetime('now','-98 hours'),  '192.168.1.42', '$IPAD', null from watchee
union all select id, datetime('now','-74 hours'),  '192.168.1.42', '$IPAD', null from watchee
union all select id, datetime('now','-52 hours'),  '192.168.1.42', '$IPAD', null from watchee
union all select id, datetime('now','-25 hours'),  '192.168.1.42', '$IPAD', 'touch=5;standalone=1' from watchee
union all select id, datetime('now','-2 hours'),   '192.168.1.42', '$IPAD', 'touch=5;standalone=1' from watchee;
update alert_state set active=0, first_triggered_at=null, last_notified_at=null;
SQL
        echo "Historie neu gesetzt, jüngster Eintrag vor 2 h."
        ;;
    bestaetigt) leer_pruefen; auf_alter 2;  echo "Jüngster Check-in vor 2 h – die Seite zeigt den Haken." ;;
    knopf)      leer_pruefen; auf_alter 7;  echo "Jüngster Check-in vor 7 h – der grüne Knopf ist zurück." ;;
    alarm)      leer_pruefen; auf_alter 27; echo "Jüngster Check-in vor 27 h – über der Schwelle von 26." ;;
    zurueck)
        leer_pruefen
        STUNDEN=${2:-}
        if [ -z "$STUNDEN" ]; then
            echo "[testdaten] Wie viele Stunden? Beispiel: bin/dev-testdaten.sh zurueck 12" >&2
            exit 1
        fi
        auf_alter "$(alter_h | awk -v s="$STUNDEN" '{print $1 + s}')"
        echo "Alles um $STUNDEN h zurückdatiert."
        ;;
    stand) ;;
    *)
        sed -n '3,25p' "$0" | sed 's/^# \{0,1\}//'
        exit 1
        ;;
esac

echo
sqlite3 -header -column "$DB" "
select datetime(occurred_at,'+2 hours') as berlin,
       round((julianday(occurred_at)-julianday(lag(occurred_at) over (order by occurred_at)))*24,1) as abstand_h,
       coalesce(device_hint,'(leer)') as geraet
from check_in order by occurred_at desc;"
echo "Alter des jüngsten Eintrags: $(alter_h) h"
