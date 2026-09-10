<?php

namespace App\Service;

use App\Entity\AlertState;
use App\Entity\CheckIn;
use App\Entity\Watchee;
use App\Repository\CheckInRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Zeichnet ein Lebenszeichen auf und beendet einen ggf. laufenden Alarm –
 * die zentrale Stelle, damit Controller und (künftige) andere Eingänge
 * dieselbe Logik nutzen.
 */
class CheckInRecorder
{
    /**
     * Älter darf ein nachgemeldeter Tipp nicht sein. Papas Seite wirft solche
     * Tipps selbst weg; kommt trotzdem einer an, stimmt etwas mit der Uhr des
     * iPads nicht, und ein Eintrag zu einem erfundenen Zeitpunkt wäre
     * schlimmer als keiner.
     */
    public const MAX_DELAY_SECONDS = 7 * 86400;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CheckInRepository $checkIns,
        private readonly LoggerInterface $logger,
        private readonly AlertNotifier $notifier,
        private readonly int $alertThresholdHours,
    ) {
    }

    /**
     * @param ?string $tapId        Kennung des Tipps, von Papas Seite vergeben
     * @param int     $delaySeconds wie lange der Tipp zurückliegt (0 = gerade eben)
     */
    public function record(
        Watchee $watchee,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $deviceHint = null,
        ?string $tapId = null,
        int $delaySeconds = 0,
    ): RecordedCheckIn {
        // Derselbe Tipp noch einmal: die Antwort auf den ersten Versuch ging
        // verloren, die Seite hat ihn deshalb erneut geschickt.
        if ($tapId !== null) {
            $existing = $this->checkIns->findOneBy(['watchee' => $watchee, 'tapId' => $tapId]);
            if ($existing !== null) {
                return new RecordedCheckIn($existing, duplicate: true, resolvedAlert: false);
            }
        }

        $receivedAt = new \DateTimeImmutable('now');
        $delaySeconds = min(max(0, $delaySeconds), self::MAX_DELAY_SECONDS);

        $checkIn = new CheckIn();
        $checkIn->setWatchee($watchee);
        // Gezählt wird der Zeitpunkt des Tippens, nicht der Ankunft: Papa hat
        // sich zu diesem Zeitpunkt gemeldet, egal wann das iPad wieder Netz hatte.
        $checkIn->setOccurredAt($receivedAt->modify(sprintf('-%d seconds', $delaySeconds)));
        $checkIn->setReceivedAt($receivedAt);
        $checkIn->setTapId($tapId);
        $checkIn->setIpAddress($ipAddress);
        $checkIn->setUserAgent($userAgent);
        $checkIn->setDeviceHint($deviceHint);

        $this->em->persist($checkIn);

        // Einen laufenden Alarm beendet nur ein Tipp, der Papa tatsächlich
        // wieder unter die Schwelle bringt. Ein Tipp, der erst Tage später
        // nachgemeldet wird, sagt nichts darüber, wie es ihm jetzt geht – und
        // die nächste Prüfung löste den Alarm sofort wieder aus.
        $resolvedAlert = false;
        $state = $watchee->getAlertState();
        if ($state instanceof AlertState && $state->isActive() && $delaySeconds < $this->alertThresholdHours * 3600) {
            $state->setActive(false);
            $state->setFirstTriggeredAt(null);
            $state->setLastNotifiedAt(null);
            $resolvedAlert = true;
        }

        $this->em->flush();

        $this->logger->info('Check-in aufgezeichnet', [
            'watchee' => $watchee->getName(),
            'resolvedAlert' => $resolvedAlert,
        ]);

        // Wenn dieser Check-in einen laufenden Alarm beendet hat, Entwarnung senden.
        if ($resolvedAlert) {
            $this->notifier->notifyResolved($watchee, $checkIn);
        }

        return new RecordedCheckIn($checkIn, duplicate: false, resolvedAlert: $resolvedAlert);
    }
}
