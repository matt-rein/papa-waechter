<?php

namespace App\Controller;

use App\Entity\CheckIn;
use App\Entity\Watchee;
use App\Repository\CheckInRepository;
use App\Repository\WatcheeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Das vollständige Protokoll der eingegangenen Lebenszeichen.
 *
 * Die Übersicht zeigt nur die letzten 30 Bestätigungen und beantwortet die
 * Frage "geht es Papa gut?". Diese Seite beantwortet eine andere: "warum kam
 * dieser Alarm?" – und dafür zählen nicht die Zeitpunkte, sondern die
 * *Abstände* zwischen ihnen. Alarmiert wird nicht, weil eine Bestätigung
 * fehlt, sondern weil zwischen zwei Bestätigungen mehr als die Schwelle lag.
 *
 * Gespeichert wird ausschließlich der Erfolg: ein Tipp, der den Server nie
 * erreicht hat (iPad kurz ohne Netz), taucht hier nicht auf. Eine Lücke, die
 * niemand erklären kann, ist deshalb genau dieser Fall.
 */
#[IsGranted('ROLE_USER')]
#[Route('/protokoll')]
class CheckInLogController extends AbstractController
{
    private const PER_PAGE = 50;

    /**
     * Auswählbare Zeiträume in Tagen; 0 steht für "alles".
     */
    private const RANGES = [7, 30, 90, 0];

    public function __construct(
        private readonly string $displayTimezone,
        private readonly int $alertThresholdHours,
    ) {
    }

    #[Route('', name: 'app_checkin_log', methods: ['GET'])]
    public function index(
        Request $request,
        WatcheeRepository $watchees,
        CheckInRepository $checkIns,
    ): Response {
        $people = $watchees->findAll();
        if ($people === []) {
            return $this->render('checkin_log/index.html.twig', [
                'people' => [],
                'watchee' => null,
            ]);
        }

        $watchee = $this->pickWatchee($people, $request->query->getInt('person'));

        $days = $request->query->getInt('tage', 7);
        if (!in_array($days, self::RANGES, true)) {
            $days = 7;
        }

        $now = new \DateTimeImmutable('now');
        $since = $days > 0 ? $now->sub(new \DateInterval('P'.$days.'D')) : null;

        $total = $checkIns->countFor($watchee, $since);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        $page = $request->query->getInt('seite', 1);
        $page = max(1, min($page, $pages));

        $entries = $checkIns->findPage($watchee, ($page - 1) * self::PER_PAGE, self::PER_PAGE, $since);

        return $this->render('checkin_log/index.html.twig', [
            'people' => $people,
            'watchee' => $watchee,
            'rows' => $this->rows($entries, $checkIns, $watchee, $now, $page),
            'timezone' => $watchee->getTimezone() ?: $this->displayTimezone,
            'thresholdHours' => $this->alertThresholdHours,
            'days' => $days,
            'ranges' => self::RANGES,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'perPage' => self::PER_PAGE,
        ]);
    }

    /**
     * @param Watchee[] $people
     */
    private function pickWatchee(array $people, int $requestedId): Watchee
    {
        foreach ($people as $person) {
            if ($person->getId() === $requestedId) {
                return $person;
            }
        }

        return $people[0];
    }

    /**
     * Reichert die Einträge um den Abstand zum jeweils vorherigen an.
     *
     * Die Liste läuft von neu nach alt, der Abstand einer Zeile bezieht sich
     * also auf die Zeile *darunter*. Für die unterste Zeile steht der
     * Vorgänger erst auf der nächsten Seite – deshalb die Extra-Abfrage.
     *
     * @param CheckIn[] $entries
     *
     * @return list<array{entry: ?CheckIn, gapHours: ?float, overdue: bool, device: string}>
     */
    private function rows(array $entries, CheckInRepository $checkIns, Watchee $watchee, \DateTimeImmutable $now, int $page): array
    {
        if ($entries === []) {
            return [];
        }

        $oldest = end($entries);
        $predecessor = $checkIns->findLastBefore($watchee, $oldest->getOccurredAt());

        $rows = [];
        $count = count($entries);

        foreach ($entries as $i => $entry) {
            $previous = $i + 1 < $count ? $entries[$i + 1] : $predecessor;

            $gapHours = $previous === null
                ? null
                : ($entry->getOccurredAt()->getTimestamp() - $previous->getOccurredAt()->getTimestamp()) / 3600;

            $rows[] = [
                'entry' => $entry,
                'gapHours' => $gapHours,
                'overdue' => $gapHours !== null && $gapHours >= $this->alertThresholdHours,
                'device' => $this->device($entry),
            ];
        }

        // Die noch offene Lücke bis jetzt gehört nur an den Anfang der ersten
        // Seite – dort ist der jüngste Eintrag überhaupt.
        if ($page === 1) {
            $openHours = ($now->getTimestamp() - $entries[0]->getOccurredAt()->getTimestamp()) / 3600;

            array_unshift($rows, [
                'entry' => null,
                'gapHours' => $openHours,
                'overdue' => $openHours >= $this->alertThresholdHours,
                'device' => '',
            ]);
        }

        return $rows;
    }

    /**
     * Aus dem User-Agent nur das, was hier zählt: kam die Bestätigung vom
     * iPad neben Papas Sessel oder von einem anderen Gerät? Der vollständige
     * String macht die Tabelle unlesbar und sagt nichts Zusätzliches.
     */
    private function device(CheckIn $entry): string
    {
        $agent = $entry->getUserAgent();
        if ($agent === null || $agent === '') {
            return '';
        }

        foreach (['iPad', 'iPhone', 'Macintosh', 'Android', 'Windows'] as $needle) {
            if (str_contains($agent, $needle)) {
                return $needle;
            }
        }

        return mb_substr($agent, 0, 24);
    }
}
