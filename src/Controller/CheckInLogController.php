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
 * Hatte das iPad beim Tippen kein Netz, schickt die Seite den Tipp später nach;
 * er steht dann mit der Uhrzeit des Tippens hier, markiert als nachgemeldet.
 * Ob ein Tipp überhaupt beim Server ankam, steht in var/log/checkin.log.
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
     * Nur das, was hier zählt: kam die Bestätigung vom iPad neben Papas Sessel
     * oder von einem anderen Gerät? Der vollständige User-Agent macht die
     * Tabelle unlesbar und sagt nichts Zusätzliches.
     *
     * Der User-Agent allein genügt dafür nicht. Safari auf dem iPad meldet sich
     * seit iPadOS 13 als "Macintosh" – Zeichen für Zeichen dieselbe Kennung wie
     * ein echter Mac. Deshalb schickt die Check-in-Seite mit, was nur der
     * Browser weiß; siehe CheckIn::$deviceHint.
     */
    private function device(CheckIn $entry): string
    {
        $agent = (string) $entry->getUserAgent();
        if ($agent === '') {
            return '';
        }

        // Die Angabe des Browsers schlägt jedes Raten am User-Agent.
        $resolved = $this->resolveApple($agent, $entry->getDeviceHint());
        if ($resolved !== null) {
            return $resolved;
        }

        foreach (['iPad', 'iPhone', 'Android', 'Windows'] as $needle) {
            if (str_contains($agent, $needle)) {
                return $needle;
            }
        }

        return mb_substr($agent, 0, 24);
    }

    /**
     * Löst die Apple-Kennung auf, soweit möglich.
     *
     * Ein iPad hat Touch-Punkte, ein Mac hat keine – das ist der einzige
     * verlässliche Unterschied. Fehlt die Angabe (Einträge von vor dieser
     * Funktion, oder kein JavaScript), bleibt die Frage offen: dann lieber die
     * Unsicherheit zeigen als eine Gewissheit behaupten, die es nicht gibt.
     *
     * @return ?string null, wenn es keine Macintosh-Kennung ist
     */
    private function resolveApple(string $agent, ?string $hint): ?string
    {
        if (!str_contains($agent, 'Macintosh')) {
            return null;
        }

        if ($hint === null || $hint === '') {
            return 'Mac oder iPad';
        }

        parse_str(str_replace(';', '&', $hint), $values);

        return ((int) ($values['touch'] ?? 0)) > 0 ? 'iPad' : 'Mac';
    }
}
