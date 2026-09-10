<?php

namespace App\Controller;

use App\Entity\CheckIn;
use App\Entity\Watchee;
use App\EventSubscriber\CheckInAccessLogSubscriber;
use App\Repository\CheckInRepository;
use App\Repository\WatcheeRepository;
use App\Service\CheckInRecorder;
use App\Service\CheckInWindow;
use App\Service\PhotoLibrary;
use App\Service\RecordedCheckIn;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Die öffentliche, login-freie Seite für die überwachte Person (Papa).
 * Zugang ausschließlich über das geheime Token im Link.
 */
class CheckInController extends AbstractController
{
    public function __construct(
        private readonly string $displayTimezone,
        private readonly PhotoLibrary $photos,
        private readonly CheckInWindow $window,
        private readonly string $assetVersion,
    ) {
    }

    #[Route('/checkin/{token}', name: 'app_checkin_show', methods: ['GET'])]
    public function show(
        string $token,
        WatcheeRepository $watchees,
        CheckInRepository $checkIns,
    ): Response {
        $watchee = $watchees->findOneByToken($token);
        if ($watchee === null) {
            // Kein Hinweis, ob das Token existiert – einfach "nicht gefunden".
            throw $this->createNotFoundException();
        }

        $response = $this->render('checkin/show.html.twig', [
            'watchee' => $watchee,
            'token' => $token,
            'state' => $this->state($watchee, $checkIns->findLatestFor($watchee), $token),
        ]);

        // Die Seite liegt als Homescreen-App auf dem iPad und wird oft aus dem
        // Hintergrund geweckt statt neu geladen. Kommt sie doch aus dem Cache,
        // zeigte sie einen längst abgelaufenen Stand – deshalb nie cachen.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Derselbe Zustand als JSON. Papas Seite fragt ihn ab, sobald sie aus dem
     * Hintergrund zurückkommt: die App bleibt auf dem iPad offen, ohne diesen
     * Abgleich stünde dort tagelang der Haken von vorgestern.
     */
    #[Route('/checkin/{token}/status', name: 'app_checkin_status', methods: ['GET'])]
    public function status(
        string $token,
        WatcheeRepository $watchees,
        CheckInRepository $checkIns,
    ): JsonResponse {
        $watchee = $watchees->findOneByToken($token);
        if ($watchee === null) {
            throw $this->createNotFoundException();
        }

        $response = new JsonResponse($this->state($watchee, $checkIns->findLatestFor($watchee), $token));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /**
     * Liefert ein Familienfoto aus. Geschützt durch dasselbe Token wie die
     * Check-in-Seite – die Bilder liegen nicht öffentlich unter public/.
     */
    #[Route('/checkin/{token}/foto/{name}', name: 'app_checkin_photo', methods: ['GET'], requirements: ['name' => PhotoController::NAME_PATTERN])]
    public function photo(
        string $token,
        string $name,
        WatcheeRepository $watchees,
    ): Response {
        if ($watchees->findOneByToken($token) === null) {
            throw $this->createNotFoundException();
        }

        // Verkleinerte Fassung – Papas iPad soll nicht 8 MB laden müssen.
        $path = $this->photos->displayPath($name);
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        return PhotoController::fileResponse($path);
    }

    /**
     * Der Tipp auf den grünen Knopf.
     *
     * Zwei Wege: checkin.js schickt ihn per fetch und will den neuen Stand als
     * JSON zurück – nur so erfährt die Seite, dass er wirklich angekommen ist,
     * und kann ihn sonst später nachmelden. Ohne JavaScript kommt ein
     * gewöhnliches Formular, dann wie immer Redirect-after-POST.
     */
    #[Route('/checkin/{token}', name: 'app_checkin_confirm', methods: ['POST'])]
    public function confirm(
        string $token,
        Request $request,
        WatcheeRepository $watchees,
        CheckInRepository $checkIns,
        CheckInRecorder $recorder,
    ): Response {
        $watchee = $watchees->findOneByToken($token);
        if ($watchee === null) {
            throw $this->createNotFoundException();
        }

        $wantsJson = \in_array('application/json', $request->getAcceptableContentTypes(), true);

        // Wie lange der Tipp zurückliegt – gemessen auf dem iPad als Abstand,
        // nicht als Uhrzeit, damit eine falsch gestellte Geräteuhr nichts
        // verschiebt. 0 bei einem frischen Tipp und ohne JavaScript.
        $delaySeconds = intdiv(self::digits($request->request->getString('vor_ms')), 1000);

        if ($delaySeconds > CheckInRecorder::MAX_DELAY_SECONDS) {
            $request->attributes->set(CheckInAccessLogSubscriber::DETAIL, \sprintf(
                'verworfen: getippt vor %s, das ist zu lange her',
                CheckInAccessLogSubscriber::duration($delaySeconds),
            ));

            // 422 heißt für checkin.js: nicht noch einmal versuchen.
            return new JsonResponse(['error' => 'Tipp zu alt'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $recorder->record(
            $watchee,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
            // Was der Browser über sich meldet; leer, wenn kein JavaScript lief.
            $request->request->getString('geraet') ?: null,
            self::tapId($request->request->getString('tap')),
            $delaySeconds,
        );

        $request->attributes->set(CheckInAccessLogSubscriber::DETAIL, $this->describe($result, $watchee));

        if ($wantsJson) {
            $response = new JsonResponse($this->state($watchee, $checkIns->findLatestFor($watchee), $token));
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        // Redirect-after-POST: verhindert versehentliches erneutes Absenden
        // beim Neuladen der Seite.
        return $this->redirectToRoute('app_checkin_show', ['token' => $token]);
    }

    /**
     * Die Zeile fürs Zugriffs-Log: was mit dem Tipp passiert ist.
     */
    private function describe(RecordedCheckIn $result, Watchee $watchee): string
    {
        $checkIn = $result->checkIn;

        if ($result->duplicate) {
            return \sprintf('schon gespeichert (#%d), derselbe Tipp noch einmal geschickt', $checkIn->getId());
        }

        $text = \sprintf('gespeichert (#%d)', $checkIn->getId());
        if ($checkIn->isLate()) {
            $tz = new \DateTimeZone($watchee->getTimezone() ?: $this->displayTimezone);
            $text = \sprintf(
                'nachgemeldet (#%d), getippt um %s, also %s vorher',
                $checkIn->getId(),
                $checkIn->getOccurredAt()->setTimezone($tz)->format('H:i:s'),
                CheckInAccessLogSubscriber::duration((int) $checkIn->getDelaySeconds()),
            );
        }

        return $result->resolvedAlert ? $text.', Alarm beendet' : $text;
    }

    /**
     * Eine Zahl aus einem Formularfeld, 0 bei allem anderen.
     */
    private static function digits(string $value): int
    {
        return $value !== '' && \strlen($value) <= 15 && ctype_digit($value) ? (int) $value : 0;
    }

    /**
     * Die Kennung des Tipps, wie checkin.js sie vergibt (UUID oder Ersatz).
     */
    private static function tapId(string $value): ?string
    {
        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $value) === 1 ? $value : null;
    }

    /**
     * Alles, was die Seite über den aktuellen Stand wissen muss – einmal für
     * das Twig-Template und einmal für den JSON-Abgleich, damit beide Wege
     * garantiert dasselbe zeigen.
     *
     * Zeitpunkte als Unix-Zeit: das Skript rechnet damit gegen die Serverzeit
     * und ist so von einer falsch gestellten iPad-Uhr unabhängig.
     *
     * @return array{
     *     name: string,
     *     confirmed: bool,
     *     now: int,
     *     expiresAt: ?int,
     *     windowHours: int,
     *     lastText: ?string,
     *     nextText: ?string,
     *     photoUrl: ?string,
     *     version: string
     * }
     */
    private function state(Watchee $watchee, ?CheckIn $last, string $token): array
    {
        $tz = new \DateTimeZone($watchee->getTimezone() ?: $this->displayTimezone);
        $now = new \DateTimeImmutable('now');
        $expiresAt = $this->window->expiresAt($last);

        $photo = $this->photos->pickForDay($now->setTimezone($tz));

        return [
            'name' => (string) $watchee->getName(),
            'confirmed' => $this->window->isConfirmed($last, $now),
            'now' => $now->getTimestamp(),
            'expiresAt' => $expiresAt?->getTimestamp(),
            // Braucht die Seite für einen Tipp, der noch unterwegs ist: auch
            // der hält den Knopf so lange weg wie ein angekommener.
            'windowHours' => $this->window->hours(),
            'lastText' => $last?->getOccurredAt()->setTimezone($tz)->format('d.m.Y \u\m H:i'),
            'nextText' => $expiresAt?->setTimezone($tz)->format('H:i'),
            'photoUrl' => $photo === null
                ? null
                : $this->generateUrl('app_checkin_photo', ['token' => $token, 'name' => $photo]),
            // Stand des Deploys. Papas App bleibt auf dem iPad offen und käme
            // sonst nie an neuen Code: sie kann sich nur selbst aktualisieren,
            // wenn sie merkt, dass auf dem Server etwas Neueres liegt.
            'version' => $this->assetVersion,
        ];
    }
}
