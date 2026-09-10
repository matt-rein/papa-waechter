<?php

namespace App\Service;

use App\Entity\AlertState;
use App\Entity\CheckIn;
use App\Entity\Watchee;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Zeichnet ein Lebenszeichen auf und beendet einen ggf. laufenden Alarm –
 * die zentrale Stelle, damit Controller und (künftige) andere Eingänge
 * dieselbe Logik nutzen.
 */
class CheckInRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly AlertNotifier $notifier,
    ) {
    }

    /**
     * @return bool true, wenn dieser Check-in einen aktiven Alarm aufgelöst hat
     */
    public function record(
        Watchee $watchee,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $deviceHint = null,
    ): bool {
        $checkIn = new CheckIn();
        $checkIn->setWatchee($watchee);
        $checkIn->setOccurredAt(new \DateTimeImmutable('now'));
        $checkIn->setIpAddress($ipAddress);
        $checkIn->setUserAgent($userAgent);
        $checkIn->setDeviceHint($deviceHint);

        $this->em->persist($checkIn);

        // Falls gerade ein Alarm aktiv war: als aufgelöst markieren.
        $resolvedAlert = false;
        $state = $watchee->getAlertState();
        if ($state instanceof AlertState && $state->isActive()) {
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
            $this->notifier->notifyResolved($watchee, $checkIn->getOccurredAt());
        }

        return $resolvedAlert;
    }
}
