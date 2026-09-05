<?php

namespace App\Service;

use App\Entity\CheckIn;

/**
 * Wie lange eine Bestätigung „steht“, bevor Papas Seite wieder den grünen
 * Knopf zeigt.
 *
 * Bewusst getrennt von der Alarmschwelle: alarmiert wird erst nach
 * ALERT_THRESHOLD_HOURS (24), der Knopf kommt aber schon nach
 * CHECKIN_WINDOW_HOURS (6) zurück. So kann Papa morgens und abends
 * bestätigen, ohne dass ein vergessener Abend gleich die Wächter aufschreckt.
 */
class CheckInWindow
{
    public function __construct(
        private readonly int $checkinWindowHours,
    ) {
    }

    public function hours(): int
    {
        return $this->checkinWindowHours;
    }

    /**
     * Zeitpunkt, ab dem wieder bestätigt werden soll – null, solange es
     * überhaupt keinen Check-in gibt.
     */
    public function expiresAt(?CheckIn $last): ?\DateTimeImmutable
    {
        $occurredAt = $last?->getOccurredAt();
        if ($occurredAt === null) {
            return null;
        }

        return $occurredAt->add(new \DateInterval('PT'.$this->checkinWindowHours.'H'));
    }

    public function isConfirmed(?CheckIn $last, ?\DateTimeImmutable $now = null): bool
    {
        $expiresAt = $this->expiresAt($last);

        return $expiresAt !== null && ($now ?? new \DateTimeImmutable('now')) < $expiresAt;
    }
}
