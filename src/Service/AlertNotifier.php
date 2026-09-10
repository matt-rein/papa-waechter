<?php

namespace App\Service;

use App\Entity\CheckIn;
use App\Entity\Watchee;
use App\Repository\GuardianRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Verschickt die Benachrichtigungen an die Wächter (Alarm, Erinnerung,
 * Entwarnung). Kapselt Empfänger- und Absender-Logik an einer Stelle.
 */
class AlertNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly GuardianRepository $guardians,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFrom,
        private readonly string $displayTimezone,
    ) {
    }

    /**
     * Erster Alarm oder wiederholte Erinnerung.
     *
     * @param array<string,mixed> $status Ergebnis von MonitoringStatus::for()
     */
    public function notifyOverdue(array $status, bool $isReminder): int
    {
        /** @var Watchee $watchee */
        $watchee = $status['watchee'];

        $subject = $isReminder
            ? \sprintf('Erinnerung: weiterhin kein Lebenszeichen von %s', $watchee->getName())
            : \sprintf('⚠️ Kein Lebenszeichen von %s seit über %d Stunden', $watchee->getName(), $status['thresholdHours']);

        return $this->send('emails/overdue.html.twig', $subject, [
            'status' => $status,
            'isReminder' => $isReminder,
            'timezone' => $this->displayTimezone,
        ]);
    }

    /**
     * Entwarnung, sobald sich die Person wieder gemeldet hat.
     */
    public function notifyResolved(Watchee $watchee, CheckIn $checkIn): int
    {
        return $this->send(
            'emails/resolved.html.twig',
            \sprintf('Entwarnung: %s hat sich wieder gemeldet', $watchee->getName()),
            [
                'watchee' => $watchee,
                'checkIn' => $checkIn,
                'timezone' => $this->displayTimezone,
            ]
        );
    }

    /**
     * @param array<string,mixed> $context
     *
     * @return int Anzahl der angeschriebenen Empfänger
     */
    private function send(string $template, string $subject, array $context): int
    {
        $recipients = $this->guardians->findAlertRecipients();
        if ($recipients === []) {
            $this->logger->warning('Alarm ausgelöst, aber kein Empfänger konfiguriert (receivesAlerts).');

            return 0;
        }

        $email = (new TemplatedEmail())
            ->from(Address::create($this->mailerFrom))
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context);

        foreach ($recipients as $guardian) {
            $email->addTo(new Address($guardian->getEmail(), $guardian->getName() ?? ''));
        }

        $this->mailer->send($email);

        $this->logger->info('Benachrichtigung versendet', [
            'template' => $template,
            'recipients' => count($recipients),
        ]);

        return count($recipients);
    }
}
