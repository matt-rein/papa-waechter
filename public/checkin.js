/* Hält Papas Seite aktuell, ohne dass jemand neu lädt.

   Hintergrund: die Seite liegt als Homescreen-App auf dem iPad und bleibt dort
   tage- oder wochenlang im Hintergrund offen. Ohne dieses Skript zeigte sie
   dann unverändert den Haken von vorgestern – und Papa sähe den grünen Knopf
   gar nicht mehr, den er antippen soll.

   Zwei Wege, bewusst unabhängig voneinander:
   1. Eine Uhr im Browser schaltet zum Ablauf des Bestätigungs-Fensters selbst
      auf den Knopf um. Das klappt auch ohne Netz.
   2. Kommt die App aus dem Hintergrund zurück, fragt sie zusätzlich den Server
      nach dem echten Stand (auch wegen des Fotos des Tages). Schlägt das fehl,
      bleibt es bei Weg 1.
   3. Meldet der Server dabei eine neuere Version, lädt die Seite sich einmal
      selbst neu. Ohne das erreicht ein Deploy ein iPad nie, auf dem die App
      seit Tagen offen liegt – der alte Code bliebe dort für immer stehen.

   Gerechnet wird gegen die Serverzeit, nicht gegen die Uhr des iPads: eine
   falsch gestellte Geräteuhr darf den Knopf nicht zu früh oder gar nicht
   zurückbringen. */
(function () {
    'use strict';

    var body = document.body;
    var viewDone = document.getElementById('view-done');
    var viewAsk = document.getElementById('view-ask');
    var lastInfo = document.getElementById('last-info');
    var lastAt = document.getElementById('last-at');
    var nextAt = document.getElementById('next-at');
    var statusUrl = body.getAttribute('data-status-url');

    /* Welches Gerät hier tippt.

       Safari auf dem iPad meldet sich seit iPadOS 13 als "Macintosh" – Zeichen
       für Zeichen wie ein echter Mac. Im Browser sind die beiden sehr wohl zu
       unterscheiden: ein iPad hat Touch-Punkte, ein Mac hat keine. Dazu
       navigator.standalone, das nur gesetzt ist, wenn die Seite vom Homescreen
       aus gestartet wurde – genau so läuft sie auf Papas iPad.

       Mitgeschickt werden die Messwerte, nicht die Deutung: was sie bedeuten,
       entscheidet der Server, und das lässt sich später ändern. */
    var hintField = document.getElementById('device-hint');
    if (hintField) {
        hintField.value = 'touch=' + (navigator.maxTouchPoints || 0) +
            ';standalone=' + (navigator.standalone === true ? '1' : '0');
    }

    var state;
    try {
        state = JSON.parse(body.getAttribute('data-state'));
    } catch (e) {
        return; // Ohne Zustand nichts anfassen – dann bleibt die Server-Fassung stehen.
    }

    // Abstand zwischen Server- und Geräteuhr, in Millisekunden.
    var skew = state.now * 1000 - Date.now();

    // Die Version, mit der diese Seite ausgeliefert wurde.
    var loadedVersion = state.version;
    var reloading = false;

    function render() {
        var confirmed = state.expiresAt !== null && (Date.now() + skew) < state.expiresAt * 1000;

        viewDone.hidden = !confirmed;
        viewAsk.hidden = confirmed;
        body.classList.toggle('confirmed', confirmed);

        lastInfo.hidden = !state.lastText;
        if (state.lastText) {
            lastAt.textContent = state.lastText;
        }
        if (state.nextText) {
            nextAt.textContent = state.nextText;
        }
    }

    /* Neuer Code auf dem Server: einmal neu laden.

       Mit Bremse, weil hier niemand zuschaut. Bliebe die Version nach dem
       Neuladen wider Erwarten alt, drehte die Seite sonst endlos im Kreis –
       auf einem Gerät, das den ganzen Tag am Ladekabel neben Papa liegt. */
    function reloadForNewVersion() {
        if (reloading) {
            return;
        }
        reloading = true;

        var now = Date.now();
        try {
            var last = parseInt(sessionStorage.getItem('lz-reload') || '0', 10);
            if (now - last < 300000) {
                return;
            }
            sessionStorage.setItem('lz-reload', String(now));
        } catch (e) {
            /* Kein sessionStorage (privater Modus): dann greift nur die
               Sperre oben, und die gilt immerhin für diesen Seitenaufbau. */
        }

        location.reload();
    }

    function refresh() {
        if (!statusUrl) {
            return;
        }

        fetch(statusUrl, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (fresh) {
                if (!fresh) {
                    return;
                }

                // Das Foto wechselt um Mitternacht – auch das bekäme eine seit
                // Tagen offene Seite sonst nie mit.
                var photo = document.getElementById('bg-photo');
                if (photo && fresh.photoUrl && fresh.photoUrl !== photo.getAttribute('src')) {
                    photo.setAttribute('src', fresh.photoUrl);
                }

                state = fresh;
                skew = state.now * 1000 - Date.now();
                render();

                if (fresh.version && loadedVersion && fresh.version !== loadedVersion) {
                    reloadForNewVersion();
                }
            })
            .catch(function () {
                /* Kein Netz: die lokale Uhr genügt, um den Knopf zurückzuholen. */
            });
    }

    render();

    // Umschalten, sobald das Fenster abläuft, während die Seite offen liegt.
    setInterval(render, 20000);

    // Abgleich mit dem Server, solange die Seite tatsächlich zu sehen ist.
    setInterval(function () {
        if (!document.hidden) {
            refresh();
        }
    }, 300000);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) {
            render();
            refresh();
        }
    });

    // pageshow feuert auch, wenn iOS die Seite aus dem Vor-/Zurück-Cache holt.
    window.addEventListener('pageshow', function () {
        render();
        refresh();
    });

    window.addEventListener('focus', refresh);
    window.addEventListener('online', refresh);
})();
