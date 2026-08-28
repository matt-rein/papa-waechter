<?php

namespace App\Controller;

use App\Repository\CheckInRepository;
use App\Repository\WatcheeRepository;
use App\Service\CheckInRecorder;
use App\Service\PhotoLibrary;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {
    }

    #[Route('/checkin/{token}', name: 'app_checkin_show', methods: ['GET'])]
    public function show(
        string $token,
        Request $request,
        WatcheeRepository $watchees,
        CheckInRepository $checkIns,
    ): Response {
        $watchee = $watchees->findOneByToken($token);
        if ($watchee === null) {
            // Kein Hinweis, ob das Token existiert – einfach "nicht gefunden".
            throw $this->createNotFoundException();
        }

        $tz = new \DateTimeZone($watchee->getTimezone() ?: $this->displayTimezone);
        $last = $checkIns->findLatestFor($watchee);

        $confirmedToday = false;
        if ($last !== null) {
            $lastLocal = $last->getOccurredAt()->setTimezone($tz);
            $nowLocal = (new \DateTimeImmutable('now'))->setTimezone($tz);
            $confirmedToday = $lastLocal->format('Y-m-d') === $nowLocal->format('Y-m-d');
        }

        return $this->render('checkin/show.html.twig', [
            'watchee' => $watchee,
            'token' => $token,
            'last' => $last,
            'confirmedToday' => $confirmedToday,
            'justConfirmed' => $request->query->getBoolean('ok'),
            'timezone' => $tz->getName(),
            // Familienfoto des Tages – wechselt zu Papas Mitternacht, nicht zu UTC.
            'photo' => $this->photos->pickForDay(new \DateTimeImmutable('now', $tz)),
        ]);
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
        return $this->redirectToRoute('app_checkin_show', ['token' => $token, 'ok' => 1]);
    }
}
