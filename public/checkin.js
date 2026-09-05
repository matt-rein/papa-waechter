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

    var state;
    try {
        state = JSON.parse(body.getAttribute('data-state'));
    } catch (e) {
        return; // Ohne Zustand nichts anfassen – dann bleibt die Server-Fassung stehen.
    }

    // Abstand zwischen Server- und Geräteuhr, in Millisekunden.
    var skew = state.now * 1000 - Date.now();

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
