<?php

namespace App\Service;

use App\Entity\CheckIn;

/**
 * Was CheckInRecorder::record() mit einem Tipp gemacht hat.
 */
final class RecordedCheckIn
{
    public function __construct(
        public readonly CheckIn $checkIn,
        /** Derselbe Tipp war schon gespeichert – nichts Neues angelegt. */
        public readonly bool $duplicate,
        /** Dieser Tipp hat einen laufenden Alarm beendet. */
        public readonly bool $resolvedAlert,
    ) {
    }
}
