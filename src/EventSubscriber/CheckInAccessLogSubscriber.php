<?php

namespace App\EventSubscriber;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Zugriffs-Log für Papas Seite: jeder Seitenaufruf, jeder Statusabruf, jeder
 * Tipp auf den Knopf – eine Zeile in var/log/checkin.log.
 *
 * Anlass: am 10.09.2026 zeigte das iPad nach einem Neustart den grünen Knopf,
 * es wurde getippt, und im Protokoll kam nichts an. Ob der Tipp den Server nie
 * erreicht hatte oder der Server ihn verloren hat, war nicht mehr zu klären:
 * die Datenbank kennt nur gespeicherte Tipps, ein Anfrage-Log gab es nicht.
 *
 * Bewusst eine schlichte Datei statt Monolog. Die Zeile muss auch dann
 * geschrieben werden, wenn die Datenbank streikt – genau dann braucht man sie.
 *
 * Das Token aus dem Link steht nicht darin: Log-Dateien werden eher mal
 * herumgeschickt als die Datenbank.
 */
final class CheckInAccessLogSubscriber implements EventSubscriberInterface
{
    /**
     * Request-Attribut, in das der Controller schreibt, was er getan hat.
     */
    public const DETAIL = '_checkin_log';

    private const ERROR = '_checkin_log_error';

    /**
     * Welche Routen ins Log kommen, und unter welchem Namen. Die Fotos fehlen
     * absichtlich – sie sagen nichts, was der Seitenaufruf nicht schon sagt.
     */
    private const ROUTES = [
        'app_checkin_show' => 'seite',
        'app_checkin_status' => 'status',
        'app_checkin_confirm' => 'knopf',
    ];

    /**
     * Ab dieser Größe wird die Datei zu checkin.log.1 und eine neue begonnen.
     * Bei einem Statusabruf alle paar Minuten reicht das für Wochen.
     */
    private const MAX_BYTES = 2_000_000;

    public function __construct(
        #[Autowire('%kernel.logs_dir%/checkin.log')]
        private readonly string $file,
        private readonly string $displayTimezone,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onException', 0],
            // Möglichst spät, damit der endgültige Statuscode feststeht.
            KernelEvents::RESPONSE => ['onResponse', -1024],
        ];
    }

    /**
     * Menschenlesbare Dauer: "45 s", "12 min", "3 h 05 min", "2 Tage 4 h".
     */
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).' min';
        }
        if ($seconds < 86400) {
            return \sprintf('%d h %02d min', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
        }

        $days = intdiv($seconds, 86400);

        return \sprintf('%d %s %d h', $days, $days === 1 ? 'Tag' : 'Tage', intdiv($seconds % 86400, 3600));
    }

    public function onException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();

        $event->getRequest()->attributes->set(
            self::ERROR,
            'Fehler: '.(new \ReflectionClass($error))->getShortName().': '.$error->getMessage(),
        );
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $what = self::ROUTES[$request->attributes->get('_route')] ?? null;
        if ($what === null) {
            return;
        }

        try {
            $this->append($this->line($request, $event->getResponse(), $what));
        } catch (\Throwable $e) {
            // Das Log darf Papas Tipp nie im Weg stehen.
            error_log('[checkin.log] '.$e->getMessage());
        }
    }

    /**
     * Beispiel:
     * 2026-09-10 14:05:12 | POST knopf  | 200 |  41 ms | Seite von 14:02:11 | touch=5;standalone=1 | gespeichert (#11) | 2003:fb:… | "Mozilla/5.0 …"
     */
    private function line(Request $request, Response $response, string $what): string
    {
        $tz = new \DateTimeZone($this->displayTimezone);
        $now = new \DateTimeImmutable('now', $tz);

        $started = $request->server->get('REQUEST_TIME_FLOAT');
        $ms = is_numeric($started) ? (int) round((microtime(true) - (float) $started) * 1000) : null;

        // Was die Seite über sich mitschickt: beim Tipp im Formular, beim
        // Statusabruf in der URL. Ein Seitenaufruf bringt nichts mit.
        // all() statt get(): get() wirft bei einem Array, und dann fehlte
        // ausgerechnet die Zeile zu einer merkwürdigen Anfrage.
        $input = ($request->isMethod('POST') ? $request->request : $request->query)->all();
        $stand = $this->stand($input['stand'] ?? null, $now);

        $detail = array_filter([
            $request->attributes->get(self::DETAIL),
            $request->attributes->get(self::ERROR),
        ]);

        return implode(' | ', [
            $now->format('Y-m-d H:i:s'),
            str_pad($request->getMethod(), 4).' '.str_pad($what, 6),
            (string) $response->getStatusCode(),
            str_pad($ms === null ? '-' : $ms.' ms', 7, ' ', STR_PAD_LEFT),
            $stand ?? '-',
            self::clean($input['geraet'] ?? null, 64) ?: '-',
            self::clean(implode(' | ', $detail), 300) ?: '-',
            $request->getClientIp() ?? '-',
            '"'.self::clean($request->headers->get('User-Agent'), 255).'"',
        ]);
    }

    /**
     * Wann die Seite zuletzt vom Server gehört hat, laut ihrem eigenen Stand.
     *
     * Beantwortet genau die Frage vom 10.09.: zeigte das iPad einen frischen
     * Zustand, oder einen, den es sich irgendwann früher gemerkt hatte?
     */
    private function stand(mixed $value, \DateTimeImmutable $now): ?string
    {
        if (!\is_string($value) || !ctype_digit($value) || \strlen($value) > 12) {
            return null;
        }

        $at = (new \DateTimeImmutable('@'.$value))->setTimezone($now->getTimezone());
        $format = $at->format('Y-m-d') === $now->format('Y-m-d') ? 'H:i:s' : 'd.m.Y H:i:s';

        return 'Seite von '.$at->format($format);
    }

    /**
     * Keine Zeilenumbrüche oder Steuerzeichen aus Browser-Angaben ins Log –
     * sonst ließe sich eine Zeile vortäuschen.
     */
    private static function clean(mixed $value, int $max): string
    {
        if (!\is_string($value)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, $max);
    }

    private function append(string $line): void
    {
        $dir = \dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (is_file($this->file) && filesize($this->file) > self::MAX_BYTES) {
            @rename($this->file, $this->file.'.1');
        }

        file_put_contents($this->file, $line."\n", FILE_APPEND | LOCK_EX);
    }
}
