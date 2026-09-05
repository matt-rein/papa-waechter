<?php

namespace App\Controller;

use App\Entity\CheckIn;
use App\Entity\Watchee;
use App\Repository\CheckInRepository;
use App\Repository\WatcheeRepository;
use App\Service\CheckInRecorder;
use App\Service\CheckInWindow;
use App\Service\PhotoLibrary;
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

    #[Route('/checkin/{token}', name: 'app_checkin_confirm', methods: ['POST'])]
    public function confirm(
        string $token,
        Request $request,
        WatcheeRepository $watchees,
        CheckInRecorder $recorder,
    ): Response {
        $watchee = $watchees->findOneByToken($token);
        if ($watchee === null) {
            throw $this->createNotFoundException();
        }

        $recorder->record(
            $watchee,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
        );

        // Redirect-after-POST: verhindert versehentliches erneutes Absenden
        // beim Neuladen der Seite.
        return $this->redirectToRoute('app_checkin_show', ['token' => $token]);
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
     *     lastText: ?string,
     *     nextText: ?string,
     *     photoUrl: ?string
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
            'lastText' => $last?->getOccurredAt()->setTimezone($tz)->format('d.m.Y \u\m H:i'),
            'nextText' => $expiresAt?->setTimezone($tz)->format('H:i'),
            'photoUrl' => $photo === null
                ? null
                : $this->generateUrl('app_checkin_photo', ['token' => $token, 'name' => $photo]),
        ];
    }
}
