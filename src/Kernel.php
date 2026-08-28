<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        // Intern rechnen und speichern wir konsequent in UTC; die Anzeige wird
        // erst im Template in die lokale Zeitzone (Europe/Berlin) umgerechnet.
        // So sind Zeitdifferenzen (z. B. "> 24h") unabhängig von der
        // Server-Zeitzone immer korrekt.
        date_default_timezone_set('UTC');

        parent::__construct($environment, $debug);
    }
}
