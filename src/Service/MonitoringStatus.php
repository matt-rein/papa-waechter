<?php

namespace App\Service;

use App\Entity\CheckIn;
use App\Entity\Watchee;
use App\Repository\CheckInRepository;

/**
 * Berechnet den aktuellen Zustand einer überwachten Person (Ampel + Details).
 * Wird sowohl vom Dashboard als auch vom Alarm-Command genutzt.
 */
class MonitoringStatus
{
    public const OK = 'ok';       // grün
    public const WARN = 'warn';   // gelb – Schwelle rückt näher
    public const ALARM = 'alarm'; // rot – Schwelle überschritten

    public function __construct(
        private readonly CheckInRepository $checkIns,
        private readonly int $alertThresholdHours,
    ) {
    }

    /**
     * @return array{
     *     watchee: Watchee,
     *     last: ?CheckIn,
     *     hoursSince: ?float,
     *     level: string,
     *     overdue: bool,
     *     thresholdHours: int
     * }
     */
    public function for(Watchee $watchee, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now');
        $last = $this->checkIns->findLatestFor($watchee);

        $hoursSince = null;
        if ($last !== null) {
            $seconds = $now->getTimestamp() - $last->getOccurredAt()->getTimestamp();
            $hoursSince = max(0, $seconds) / 3600;
        }

        $overdue = $hoursSince === null || $hoursSince >= $this->alertThresholdHours;

        $level = self::OK;
        if ($overdue) {
            $level = self::ALARM;
        } elseif ($hoursSince !== null && $hoursSince >= ($this->alertThresholdHours - 4)) {
            // In den letzten 4 Stunden vor der Schwelle: Vorwarnung (gelb).
            $level = self::WARN;
        }

        return [
            'watchee' => $watchee,
            'last' => $last,
            'hoursSince' => $hoursSince,
            'level' => $level,
            'overdue' => $overdue,
            'thresholdHours' => $this->alertThresholdHours,
        ];
    }
}
