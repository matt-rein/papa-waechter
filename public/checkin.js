/* Hält Papas Seite aktuell, ohne dass jemand neu lädt.

   Hintergrund: die Seite liegt als Homescreen-App auf dem iPad und bleibt dort
   tage- oder wochenlang im Hintergrund offen. Ohne dieses Skript zeigte sie
   dann unverändert den Haken von vorgestern – und Papa sähe den grünen Knopf
   gar nicht mehr, den er antippen soll.

   Mehrere Wege, bewusst unabhängig voneinander:
   1. Eine Uhr im Browser schaltet zum Ablauf des Bestätigungs-Fensters selbst
      auf den Knopf um. Das klappt auch ohne Netz.
   2. Kommt die App aus dem Hintergrund zurück, fragt sie zusätzlich den Server
      nach dem echten Stand (auch wegen des Fotos des Tages). Schlägt das fehl,
      bleibt es bei Weg 1.
   3. Meldet der Server dabei eine neuere Version, lädt die Seite sich einmal
      selbst neu. Ohne das erreicht ein Deploy ein iPad nie, auf dem die App
      seit Tagen offen liegt – der alte Code bliebe dort für immer stehen.
   4. Ein Tipp auf den Knopf gilt erst als erledigt, wenn der Server ihn
      bestätigt hat. Bis dahin merkt sich die Seite ihn und schickt ihn nach,
      sobald wieder Netz da ist – auch nach einem Neustart des iPads.

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
    var doneSent = document.getElementById('done-sent');
    var doneSending = document.getElementById('done-sending');
    var doneOffline = document.getElementById('done-offline');
    var form = document.getElementById('confirm-form');
    var statusUrl = body.getAttribute('data-status-url');

    /* Welches Gerät hier tippt.

       Safari auf dem iPad meldet sich seit iPadOS 13 als "Macintosh" – Zeichen
       für Zeichen wie ein echter Mac. Im Browser sind die beiden sehr wohl zu
       unterscheiden: ein iPad hat Touch-Punkte, ein Mac hat keine. Dazu
       navigator.standalone, das nur gesetzt ist, wenn die Seite vom Homescreen
       aus gestartet wurde – genau so läuft sie auf Papas iPad.

       Mitgeschickt werden die Messwerte, nicht die Deutung: was sie bedeuten,
       entscheidet der Server, und das lässt sich später ändern. */
    var deviceHint = 'touch=' + (navigator.maxTouchPoints || 0) +
        ';standalone=' + (navigator.standalone === true ? '1' : '0');

    var hintField = document.getElementById('device-hint');
    if (hintField) {
        hintField.value = deviceHint;
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

    /* Tipps, die der Server noch nicht bestätigt hat.

       Sie stehen in localStorage, das übersteht auch einen Neustart des iPads.
       Mitgeschickt wird nicht die Uhrzeit des Tippens, sondern wie lange es
       her ist; den Zeitpunkt rechnet der Server gegen seine eigene Uhr zurück.
       So verschiebt eine falsch gestellte iPad-Uhr nichts – den Abstand misst
       auch eine falsche Uhr richtig.

       Jeder Tipp hat eine eigene Kennung. Kam er an, aber die Antwort ging
       unterwegs verloren, schickt die Seite ihn erneut – und der Server
       erkennt ihn wieder, statt ihn doppelt zu speichern. */
    var QUEUE_KEY = 'lz-offen';
    // Muss zu CheckInRecorder::MAX_DELAY_SECONDS passen: ältere nimmt der Server nicht an.
    var MAX_AGE = 7 * 24 * 3600 * 1000;
    var RETRY_AFTER = 30000;
    var SEND_TIMEOUT = 20000;

    var queue = loadQueue();
    var sending = false;
    var failed = false;
    var lastAttempt = 0;

    /* Wie lange die volle Bestätigung stehen bleibt, bevor sie zur Zeile am
       unteren Rand schrumpft. Der Haken ist die Quittung für den Tipp; danach
       verdeckt die Karte nur noch das Familienfoto. */
    var SLIM_AFTER = 4000;
    // So lange darf die Karte sichtbar zusammenschrumpfen (siehe .slimming im CSS).
    var SLIM_OUT = 380;
    var slim = false;
    var slimming = false;
    var slimTimer = null;
    // Ob der Tipp in dieser Sitzung gefallen ist – siehe updateSlim().
    var tappedHere = false;

    function loadQueue() {
        var list;
        try {
            list = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
        } catch (e) {
            list = [];
        }
        if (!Array.isArray(list)) {
            return [];
        }

        var now = Date.now();
        return list.filter(function (tap) {
            return tap && typeof tap.id === 'string' && typeof tap.at === 'number' && now - tap.at < MAX_AGE;
        });
    }

    function saveQueue() {
        try {
            localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
        } catch (e) {
            /* Ohne localStorage bleibt der Tipp nur gemerkt, solange die
               Seite offen ist. Besser als gar nicht. */
        }
    }

    function forget(id) {
        queue = queue.filter(function (tap) {
            return tap.id !== id;
        });
        saveQueue();
    }

    function newTapId() {
        if (window.crypto && typeof crypto.randomUUID === 'function') {
            return crypto.randomUUID();
        }
        return 't' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
    }

    // Nur eine Antwort mit unserem Zustand zählt als "angekommen". Ein WLAN
    // mit Anmeldeseite (Hotel, Praxis) antwortet auch mit 200 – bei uns ist
    // der Tipp dann aber nie gelandet.
    function isState(value) {
        return value !== null && typeof value === 'object' &&
            typeof value.now === 'number' && 'expiresAt' in value;
    }

    function show(element, visible) {
        if (element) {
            element.hidden = !visible;
        }
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    /* Wann die Bestätigung zur schmalen Zeile am unteren Rand wird.

       Erst, wenn der Tipp wirklich beim Server liegt: solange einer noch
       unterwegs ist, steht in der Karte, dass er nachgeschickt wird – das darf
       nicht wegschrumpfen, bevor es jemand gelesen hat.

       Wer die App nur wieder aufmacht, hat das "Danke" längst gesehen; dann
       gleich schmal, statt das Foto noch einmal zehn Sekunden zu verdecken. */
    function updateSlim(delivered) {
        if (!delivered) {
            if (slimTimer) {
                clearTimeout(slimTimer);
                slimTimer = null;
            }
            slim = false;
            slimming = false;

            return;
        }

        if (slim || slimTimer) {
            return;
        }

        if (!tappedHere) {
            slim = true;

            return;
        }

        // Erst schrumpft die Karte weg, dann gleitet die Zeile von unten herein.
        slimTimer = setTimeout(function () {
            slimming = true;
            render();
            slimTimer = setTimeout(function () {
                slimTimer = null;
                slimming = false;
                slim = true;
                render();
            }, SLIM_OUT);
        }, SLIM_AFTER);
    }

    function render() {
        var now = Date.now();
        var serverConfirmed = state.expiresAt !== null && (now + skew) < state.expiresAt * 1000;

        // Ein Tipp, der noch unterwegs ist, zählt auf dem iPad schon: Papa hat
        // seinen Teil getan, der Knopf bleibt so lange weg wie nach einem
        // angekommenen. Gerechnet mit der Geräteuhr – ohne Netz gibt es keine andere.
        var newest = queue.length ? queue[queue.length - 1].at : null;
        var localUntil = newest === null ? null : newest + (state.windowHours || 0) * 3600000;
        var localConfirmed = localUntil !== null && now < localUntil;

        var confirmed = serverConfirmed || localConfirmed;

        viewDone.hidden = !confirmed;
        viewAsk.hidden = confirmed;
        body.classList.toggle('confirmed', confirmed);

        var waiting = queue.length > 0;
        show(doneSent, !waiting);
        show(doneSending, waiting && !failed);
        show(doneOffline, waiting && failed);

        updateSlim(confirmed && !waiting);
        body.classList.toggle('slim', slim);
        body.classList.toggle('slimming', slimming);

        lastInfo.hidden = !state.lastText;
        if (state.lastText) {
            lastAt.textContent = state.lastText;
        }
        if (localConfirmed && !serverConfirmed) {
            var until = new Date(localUntil);
            nextAt.textContent = pad(until.getHours()) + ':' + pad(until.getMinutes());
        } else if (state.nextText) {
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

    // Neuer Stand vom Server – aus dem Statusabruf oder als Antwort auf einen Tipp.
    function apply(fresh) {
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
    }

    function refresh() {
        if (!statusUrl) {
            return;
        }

        // stand und geraet liest nur das Zugriffs-Log auf dem Server.
        var url = statusUrl + '?stand=' + encodeURIComponent(state.now) +
            '&geraet=' + encodeURIComponent(deviceHint);

        fetch(url, { cache: 'no-store', credentials: 'same-origin' })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (fresh) {
                if (!isState(fresh)) {
                    return;
                }
                apply(fresh);
                // Der Server ist erreichbar – gute Gelegenheit für Liegengebliebenes.
                send();
            })
            .catch(function () {
                /* Kein Netz: die lokale Uhr genügt, um den Knopf zurückzuholen. */
            });
    }

    /* Den ältesten offenen Tipp an den Server schicken. Klappt es, gleich den
       nächsten hinterher – es können mehrere sein, wenn das iPad morgens und
       abends ohne Netz war. */
    function send() {
        if (sending || queue.length === 0 || !form) {
            return;
        }
        sending = true;
        lastAttempt = Date.now();

        var tap = queue[0];
        var data = new FormData();
        data.append('geraet', deviceHint);
        data.append('tap', tap.id);
        data.append('vor_ms', String(Math.max(0, Date.now() - tap.at)));
        data.append('stand', String(state.now));

        // Ohne Zeitlimit hinge ein Versuch in einem wackligen WLAN beliebig
        // lange – und blockierte alle weiteren.
        var controller = typeof AbortController === 'function' ? new AbortController() : null;
        var timer = controller ? setTimeout(function () { controller.abort(); }, SEND_TIMEOUT) : null;

        function finish(delivered, fresh) {
            if (timer) {
                clearTimeout(timer);
            }
            sending = false;
            failed = !delivered;

            if (delivered) {
                forget(tap.id);
            }
            if (fresh) {
                apply(fresh);
            } else {
                render();
            }
            if (delivered && queue.length) {
                send();
            }
        }

        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: data,
            headers: { Accept: 'application/json' },
            cache: 'no-store',
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                // 422: der Server nimmt diesen Tipp nie an (zu alt). Ihn
                // weiter zu schicken, änderte nichts.
                if (response.status === 422) {
                    finish(true, null);
                    return;
                }
                if (!response.ok) {
                    finish(false, null);
                    return;
                }
                return response.json().then(function (fresh) {
                    finish(isState(fresh), isState(fresh) ? fresh : null);
                }, function () {
                    finish(false, null);
                });
            })
            .catch(function () {
                finish(false, null);
            });
    }

    if (form && window.fetch && window.FormData) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            queue.push({ id: newTapId(), at: Date.now() });
            saveQueue();
            failed = false;
            tappedHere = true;
            render();
            send();
        });
    }

    render();
    // Was beim letzten Mal liegen blieb, gleich versuchen.
    send();

    // Umschalten, sobald das Fenster abläuft, während die Seite offen liegt –
    // und offene Tipps immer wieder anbieten, solange es welche gibt.
    setInterval(function () {
        render();
        if (queue.length && Date.now() - lastAttempt >= RETRY_AFTER) {
            send();
        }
    }, 20000);

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
            send();
        }
    });

    // pageshow feuert auch, wenn iOS die Seite aus dem Vor-/Zurück-Cache holt.
    window.addEventListener('pageshow', function () {
        render();
        refresh();
        send();
    });

    window.addEventListener('focus', refresh);
    window.addEventListener('online', function () {
        refresh();
        send();
    });
})();
