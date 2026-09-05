# Papa-Wächter 🟢

Ein „Lebenszeichen"-System (Dead-Man's-Switch): Papa bestätigt morgens und abends
per Tipp auf seinem iPad, dass alles in Ordnung ist. Bleibt die Bestätigung länger
als 24 Stunden aus, werden die Wächter (z. B. Matthias und Schwester) automatisch
per E-Mail alarmiert.

Gebaut mit Symfony 7, SQLite und FrankenPHP. **Ohne CDN** – alle Assets werden
lokal ausgeliefert.

---

## Wie es funktioniert

- **Papa** öffnet ein Homescreen-Icon (eine Web-Seite als PWA) und tippt auf den
  großen Knopf **„Mir geht's gut"**. Kein Login, Zugang über einen geheimen Link.
- **Zwei getrennte Fristen.** Nach einer Bestätigung zeigt die Seite den Haken und
  nennt die Uhrzeit, ab der der Knopf wieder da ist – nach `CHECKIN_WINDOW_HOURS`
  (Standard 6). So passt „morgens und abends“ hinein. **Alarmiert** wird davon
  unabhängig erst nach `ALERT_THRESHOLD_HOURS` (Standard 24): ein vergessener
  Abend weckt niemanden.
- **Die Seite hält sich selbst aktuell.** Papas App bleibt auf dem iPad im
  Hintergrund offen, oft tagelang. `public/checkin.js` schaltet deshalb beim
  Ablauf des Fensters von selbst auf den Knopf um (eigene Uhr, geht auch ohne
  Netz) und gleicht sich beim Zurückkehren aus dem Hintergrund über
  `/checkin/{token}/status` mit dem Server ab – Zustand, Uhrzeiten und das Foto
  des Tages. Gerechnet wird gegen die Serverzeit, damit eine falsch gestellte
  iPad-Uhr nichts verschiebt. Die HTML-Seite selbst wird mit `no-store`
  ausgeliefert, damit beim Neustart der App nie ein alter Stand aus dem Cache
  kommt.
- **Wächter** melden sich am Dashboard an und sehen eine Status-Ampel
  (grün / gelb / rot) sowie den Verlauf aller Bestätigungen. Auf der Startseite
  steht außerdem eine aufklappbare **Bedienungsanleitung** (Homescreen-Symbol
  einrichten, Wächter anlegen, Ampel, Alarmfall, Störungen).
- Im Hintergrund von Papas Seite liegt jeden Tag ein anderes **Familienfoto**.
  Die Bilder pflegen die Wächter unter `/fotos`.
- Ein **Cron-Job** (`app:check-alive`) prüft regelmäßig, ob die letzte Bestätigung
  zu lange her ist, und verschickt bei Überfälligkeit die Alarm-Mail. Meldet sich
  Papa wieder, geht eine **Entwarnung** raus.

## Wichtige Bausteine

| Datei | Zweck |
|---|---|
| `src/Entity/` | `Guardian`, `Watchee` (Papa), `CheckIn`, `AlertState` |
| `src/Controller/CheckInController.php` | Papas Seite (`/checkin/{token}`) + Status als JSON |
| `src/Service/CheckInWindow.php` | wie lange eine Bestätigung „steht“ |
| `public/checkin.js` | hält Papas Seite ohne Neuladen aktuell |
| `src/Controller/DashboardController.php` | Dashboard (`/`) inkl. Bedienungsanleitung |
| `src/Controller/GuardianController.php` | Wächter-Verwaltung (`/waechter`) |
| `src/Controller/PhotoController.php` | Foto-Verwaltung (`/fotos`) |
| `src/Service/PhotoLibrary.php` | Ablage der Fotos + Auswahl des Tagesbildes |
| `photos/` | die Familienbilder (nicht im Repo, nicht im Image) |
| `templates/dashboard/_manual.html.twig` | Text der Bedienungsanleitung |
| `src/Command/CheckAliveCommand.php` | die 24h-Prüfung (`app:check-alive`) |
| `bin/cron-check-alive.sh` | Aufruf der Prüfung für klassische Cron-Dienste |
| `src/Service/` | Check-in-Aufzeichnung, Statusberechnung, Mailversand |

---

## Lokal starten (ohne Docker)

```bash
composer install
php bin/console doctrine:migrations:migrate
php bin/console doctrine:fixtures:load        # Demo-Daten (Papa + 2 Wächter)
symfony server:start -d --no-tls              # http://127.0.0.1:8000
```

Demo-Zugang nach dem Laden der Fixtures:

- **Papa-Link:** <http://127.0.0.1:8000/checkin/demo-token-papa-0000000000000000>
- **Dashboard-Login:** `mre@reinhardt-online.de` / Passwort `changeme`
  (zweiter Wächter: `schwester@example.com` / `changeme`)

Die 24h-Prüfung manuell auslösen:

```bash
php bin/console app:check-alive            # echt (versendet ggf. Mails)
php bin/console app:check-alive --dry-run  # nur anzeigen, nichts senden
```

---

## Lokal mit Docker testen

```bash
docker compose up --build
```

- App: <http://localhost:8080>
- Bilder-Ordner: `photos/` im Projekt – Änderungen sind sofort wirksam.
- **Mailpit** (fängt alle Mails ab): <http://localhost:8025>
- Der `cron`-Dienst prüft lokal alle **60 Sekunden**, damit man den Alarm schnell
  sieht (in Produktion: 15 Minuten).

Zum schnellen Testen des Alarms: einfach ~1 Minute nicht einchecken bzw. den
letzten Check-in in der DB löschen – die Alarm-Mail erscheint dann in Mailpit.

---

## Produktion: DomainFactory (ohne Docker)

Die Domain ist **reinhardt.onl**, PHP ist `php84`, deployt wird per GitHub
Actions (`.github/workflows/deploy.yml`) bei jedem Push auf `main`.

> **PHP 8.4 ist Pflicht, nicht Geschmackssache.** Die Doctrine-Pakete in der
> `composer.lock` verlangen `^8.4`; unter 8.3 startet die App nicht.
> `composer.json` pinnt die Auflösung über `config.platform.php` auf 8.4, damit
> ein `composer update` auf einem neueren lokalen PHP nichts einschleppt, was
> auf dem Server fehlt.

### Einmalige Einrichtung auf dem Server

1. **Doc-Root der Domain auf `public/` setzen** (im DomainFactory-Kundenmenü).
   Ohne das läge der komplette Quellcode im Web.
2. **`.env.local` anlegen** – Vorlage ist `.env.local.dist`. Darin muss
   `APP_ENV=prod` stehen; der Deploy bricht sonst mit einer Fehlermeldung ab,
   damit die Seite nicht versehentlich im Entwicklungsmodus läuft.
3. **Ordner anlegen und schreibbar machen:** `data/`, `photos/`, `var/`.
   (Der Deploy legt sie zur Sicherheit auch selbst an.)
4. **Erste Daten anlegen** – per SSH im Projektverzeichnis:

   ```bash
   php84 bin/console doctrine:migrations:migrate
   php84 bin/console app:guardian:create    # Dashboard-Login
   php84 bin/console app:watchee:create     # gibt den Check-in-Link fürs iPad aus
   ```

5. **Cron-Job eintragen.** DomainFactory startet nur Shell-Skripte, deshalb
   liegt eines im Projekt. Alle 15 Minuten aufrufen:

   ```
   /kunden/135359_61348/watcher/bin/cron-check-alive.sh
   ```

   Das Skript findet sein Projektverzeichnis selbst, sucht ein passendes PHP
   (mindestens 8.4 – das nackte `php` ist am df.eu 7.4) und schreibt jeden Lauf
   nach `var/log/check-alive.log`. Bei Erfolg bleibt es still; im Fehlerfall
   geht die Ausgabe auf stderr, woraus der Cron-Dienst eine E-Mail an dich
   macht.

   Zwei Dinge lassen sich in `.env.local` einstellen – dort, weil der Deploy
   diese Datei nie anfasst, Änderungen im Skript selbst aber überschreiben
   würde:

   ```
   CRON_PHP_BIN=/usr/bin/php84
   HEALTHCHECK_PING_URL=https://hc-ping.com/DEINE-UUID
   ```

   Die zweite Zeile ist der **Wächter für den Wächter**: Nach jedem
   erfolgreichen Lauf pingt das Skript einen kostenlosen Check bei
   healthchecks.io an. Bleibt der Ping aus, meldet sich dieser Dienst bei dir.
   Ohne ihn bemerkt niemand, wenn der Cron stehenbleibt – und dann alarmiert
   im Ernstfall auch niemand.

### GitHub-Secrets

| Secret | Bedeutung |
|---|---|
| `DEPLOY_SSH_KEY` | privater SSH-Schlüssel (der öffentliche liegt am Server) |
| `DEPLOY_SSH_HOST` | SSH-Host bei DomainFactory |
| `DEPLOY_SSH_USER` | SSH-Benutzer |
| `DEPLOY_PATH` | Projektverzeichnis auf dem Server (ohne `/public`) |
| `DEPLOY_SSH_PORT` | optional, Standard 22 |
| `DEPLOY_PHP_BIN` | optional, Standard `php84` (nacktes `php` ist dort 7.4) |
| `DEPLOY_HEALTHCHECK_URL` | optional, z. B. `https://reinhardt.onl/login` |

### Was der Deploy macht

Bauen in der Action (`composer install --no-dev`), dann `rsync` auf den Server,
dann per SSH: Datenbank-Backup, `APP_ASSET_VERSION` auf den Git-SHA setzen,
`cache:clear`, Migrationen, `cache:warmup`. Zum Schluss ein HTTP-Check.

Einen Asset-Build gibt es nicht – CSS und JavaScript liegen fertig in `public/`
(auf dem Dashboard steht daneben etwas JavaScript inline in den Templates).
CSS-, JS-, Template- und Code-Änderungen erledigt der `rsync`. Damit Browser die neuen Dateien auch wirklich holen, hängt Symfony an
jede Asset-URL `?v=<Git-SHA>`; das ist besonders für Papas Seite wichtig, die als
Homescreen-App im Cache liegt.

### Was der Deploy niemals anfasst

`rsync --delete` würde gitignorierte Ordner auf dem Server löschen – deshalb sind
sie ausgenommen:

| Ordner | Inhalt |
|---|---|
| `data/` | die SQLite-Datei mit allen Check-ins (+ die letzten 10 Backups) |
| `photos/` | die Familienbilder |
| `var/` | Cache, Logs, Bild-Cache |
| `.env.local` | Zugangsdaten |

Vor jeder Migration legt der Deploy eine Kopie `data/data_prod.db.backup-<Datum>`
an und behält die letzten zehn.

---

## Alternative: Produktion mit Docker

Wird für reinhardt.onl nicht verwendet – bleibt aber lauffähig, falls das
Projekt einmal auf einen eigenen Server umzieht. FrankenPHP kümmert sich dann
automatisch um HTTPS (Let's Encrypt).

```bash
cp .env.prod.local.dist .env.prod.local     # ausfüllen: SERVER_NAME, APP_SECRET, MAILER_DSN ...
docker compose -f compose.prod.yaml up -d --build
```

Danach einmalig die echten Daten anlegen (nicht die Demo-Fixtures verwenden!):

```bash
# Ersten Wächter anlegen (Dashboard-Login + Alarm-Empfänger)
docker compose -f compose.prod.yaml exec app php bin/console app:guardian:create

# Überwachte Person anlegen – gibt den Check-in-Link fürs iPad aus
docker compose -f compose.prod.yaml exec app php bin/console app:watchee:create
```

---

## Betrieb (gilt für beide Betriebsarten)

Den Check-in-Link auf Papas iPad in Safari öffnen und über das Teilen-Menü
**„Zum Home-Bildschirm"** hinzufügen. Der Link steht auch im Dashboard in der
Bedienungsanleitung, mit „Kopieren"-Knopf.

### Wächter verwalten

Nach dem ersten Wächter geht alles Weitere über die Oberfläche unter
**`/waechter`** (Kopfzeile → „Wächter“): anlegen, löschen, Alarm-Mails an- und
abschalten, Passwörter setzen. Alle Wächter sind gleichberechtigt, eine
Admin-Rolle gibt es bewusst nicht. Der letzte Wächter lässt sich nicht löschen,
und niemand kann sich selbst löschen.

Kommt niemand mehr ins Dashboard (alle Passwörter vergessen), hilft die
Kommandozeile – auf dem Server per SSH, im Container per `exec`:

```bash
php84 bin/console app:guardian:password --generate
docker compose -f compose.prod.yaml exec app php bin/console app:guardian:password --generate
```

### Familienfotos

Papas Check-in-Seite zeigt im Hintergrund ein Familienfoto – pro Kalendertag
eines, und erst wenn alle einmal dran waren, geht es von vorne los (dann in
neuer Reihenfolge, und nie dasselbe Bild an zwei Tagen hintereinander).

Verwaltet werden sie im Dashboard unter **`/fotos`** (Kopfzeile → „Fotos").
Verkleinert (max. 2560 px) und per EXIF gerade gedreht wird **erst beim
Ausliefern**; das Ergebnis liegt in `var/photo-cache/`, die Originale bleiben
unverändert. Dafür braucht das Image `gd` und `exif` – beides ist im Dockerfile
enthalten. Fehlen sie, wird einfach das Original ausgeliefert.

**Zwei Wege, Bilder hinzuzufügen:**

1. **Hochladen** über `/fotos` – geht von jedem Gerät.
2. **In den Ordner ziehen:** die Dateien einfach nach **`photos/`** legen, mit
   ihren eigenen Namen (Leerzeichen und Umlaute sind erlaubt). Erkannt werden
   JPG, PNG, WebP und GIF; Unterordner werden nicht durchsucht. Dateien, die
   kein anzeigbares Bild sind, listet `/fotos` unter „Nicht verwendet" auf, damit
   man nicht rätselt.

Der Ordner liegt bewusst **neben dem Projekt und nicht unter `var/`** – dort
wird bei Problemen gern mal aufgeräumt (Cache leeren, Ordner löschen), und die
Originale wären weg. In `var/photo-cache/` landen nur die verkleinerten
Fassungen; die dürfen jederzeit gelöscht werden und entstehen neu.

Die Bilder sind **nicht im Repo** (siehe `photos/.gitignore`), **nicht im
Docker-Image** (`.dockerignore`) und **vom Deploy ausgenommen**. Auf
DomainFactory ist es schlicht der Ordner `photos/` im Projektverzeichnis; im
Docker-Betrieb mountet `compose.prod.yaml` ihn neben die compose-Datei.

Öffentlich erreichbar sind sie nicht: ausgeliefert werden sie nur über Papas
Check-in-Link (`/checkin/{token}/foto/{name}`) oder an angemeldete Wächter.
Originale bleiben unangetastet, verkleinert wird erst beim Ausliefern.

HEIC-Bilder vom iPhone werden abgewiesen, weil kein Browser sie anzeigt – die
Fehlermeldung erklärt, wie man das iPhone auf JPEG umstellt.

### Betriebshinweise

- **Wächter für den Wächter:** Fällt der Server aus, läuft auch kein Cron und es
  kommt kein Alarm. Empfehlung: den `app:check-alive`-Lauf zusätzlich von einem
  externen Dienst (z. B. healthchecks.io) überwachen lassen (Ping am Ende des
  Loops ergänzen).
- **Healthcheck des Cron-Dienstes** (nur Docker-Betrieb): nach jedem *erfolgreichen* Durchlauf fasst
  `check-alive-loop.sh` die Datei `var/cron-heartbeat` an;
  `docker/cron-healthcheck.sh` schlägt Alarm, wenn sie älter als zwei Intervalle
  ist. So fällt auch ein Dienst auf, der zwar noch läuft, dessen Prüfung aber
  dauerhaft scheitert – `docker compose ps` zeigt ihn dann als `unhealthy`.
- **HTTPS ist Pflicht**, weil das geheime Token im Link steckt.
- **Token vertraulich halten;** bei Bedarf mit `app:watchee:create` neu erzeugen.
- **Passwort zurücksetzen:** `app:guardian:password [E-Mail] [--generate]` –
  ohne E-Mail erscheint eine Auswahlliste.
- **Sicherung:** die beiden Ordner `data/` (Datenbank) und `photos/` (Bilder)
  mitkopieren – mehr braucht es nicht. Alles andere kommt aus dem Repo.

---

## Konfiguration (`.env`)

| Variable | Bedeutung |
|---|---|
| `MAILER_DSN` | Mailversand (SMTP, Gmail, Brevo …) |
| `MAILER_FROM` | Absender der Alarm-Mails |
| `ALERT_THRESHOLD_HOURS` | Stunden ohne Lebenszeichen bis zum Alarm (Standard 24) |
| `ALERT_REMINDER_HOURS` | Erinnerungsintervall, solange der Alarm aktiv ist (Standard 12) |
| `CHECKIN_WINDOW_HOURS` | Stunden, bis Papas Seite wieder den grünen Knopf zeigt (Standard 6) |
| `APP_TIMEZONE` | Anzeige-Zeitzone (Standard `Europe/Berlin`) |
| `DEFAULT_URI` | Basis-URL für Links in Mails/CLI |
| `APP_ASSET_VERSION` | Cache-Busting für CSS; setzt der Deploy auf den Git-SHA |
| `APP_PHOTO_DIR_LABEL` | Bilder-Ordner, wie er auf `/fotos` zum Kopieren angezeigt wird |

> Intern werden alle Zeitpunkte in **UTC** gespeichert und erst bei der Anzeige in
> die lokale Zeitzone umgerechnet.

## Datenschutz / Einwilligung

Das System läuft mit **Papas ausdrücklicher Zustimmung**. Es werden bewusst nur
minimale Daten gespeichert (Zeitpunkt der Bestätigung, IP/Gerät zur Nachvollzieh­-
barkeit).
