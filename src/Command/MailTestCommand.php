<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Validator\Constraints\Email as EmailConstraint;
use Symfony\Component\Validator\Validation;

/**
 * Verschickt eine einzelne Testmail.
 *
 * Der Grund für dieses Kommando: die Alarm-Mail geht als *eine* Nachricht an
 * alle Wächter zugleich. Bleibt sie bei genau einem Empfänger aus, lässt sich
 * das sonst nur prüfen, indem man einen Tag auf einen echten Alarm wartet.
 * Hier geht die Mail an genau eine Adresse – und im Fehlerfall steht die
 * Antwort des Mailservers im Klartext auf dem Schirm.
 */
#[AsCommand(
    name: 'app:mail:test',
    description: 'Schickt eine Testmail an eine Adresse und zeigt die Antwort des Mailservers.',
)]
class MailTestCommand extends Command
{
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly string $mailerFrom,
        private readonly string $mailerDsn,
        private readonly string $displayTimezone,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Empfänger der Testmail');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipient = trim((string) $input->getArgument('email'));

        $violations = Validation::createValidator()->validate($recipient, new EmailConstraint());
        if (count($violations) > 0) {
            $io->error(sprintf('"%s" ist keine gültige E-Mail-Adresse.', $recipient));

            return Command::INVALID;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone($this->displayTimezone));
        $marker = $now->format('d.m.Y H:i:s');

        $io->definitionList(
            ['Versandweg' => $this->maskDsn($this->mailerDsn)],
            ['Absender' => $this->mailerFrom],
            ['Empfänger' => $recipient],
        );

        if (str_starts_with($this->mailerDsn, 'null://')) {
            $io->warning('MAILER_DSN ist "null://null" – es wird nichts wirklich verschickt.');
        }

        $email = (new Email())
            ->from(Address::create($this->mailerFrom))
            ->to($recipient)
            ->subject('Testmail vom Papa-Wächter ('.$marker.')')
            ->text(
                "Das ist eine Testmail des Papa-Wächters.\n\n".
                "Kommt sie an, funktioniert der Versandweg bis zu dieser Adresse –\n".
                "dann kommen auch die Alarm-Mails an.\n\n".
                "Landet sie im Spam-Ordner, bitte auf \"Kein Spam\" setzen und den\n".
                "Absender ins Adressbuch aufnehmen. Sonst trifft es im Ernstfall\n".
                "die Alarm-Mail genauso.\n\n".
                'Verschickt am '.$marker." Uhr.\n"
            );

        try {
            $sent = $this->transport->send($email);
        } catch (TransportExceptionInterface $e) {
            // Genau dafür gibt es das Kommando: die Klartext-Antwort des
            // Mailservers, statt eines Stacktrace irgendwo im Cron-Log.
            $io->error('Der Mailserver hat die Nachricht abgelehnt.');
            $io->writeln('<comment>'.$e->getMessage().'</comment>');

            return Command::FAILURE;
        }

        $io->success('Mail wurde an den Mailserver übergeben.');

        $messageId = $sent->getMessageId();
        if ($messageId !== null && $messageId !== '') {
            $io->writeln('Kennung des Mailservers: <info>'.$messageId.'</info>');
            $io->writeln('Damit kann der Hoster die Zustellung nachverfolgen.');
        }

        $io->note(
            "Angenommen heißt noch nicht zugestellt: Ob die Mail beim Empfänger\n".
            "im Posteingang, im Spam-Ordner oder gar nicht landet, entscheidet\n".
            'dessen Anbieter. Bitte dort nachsehen lassen.'
        );

        return Command::SUCCESS;
    }

    /**
     * Das Passwort steht im DSN – es hat in keiner Ausgabe etwas zu suchen.
     *
     * Bewusst nicht über parse_url(): bei DomainFactory ist der Benutzername
     * oft selbst eine E-Mail-Adresse, das "@" darin bringt jede naive
     * Zerlegung durcheinander. Deshalb am *letzten* "@" trennen – das ist
     * immer das vor dem Host – und in den Zugangsdaten alles hinter dem
     * letzten ":" schwärzen. Im Zweifel wird lieber zu viel maskiert.
     */
    private function maskDsn(string $dsn): string
    {
        $hostStart = strrpos($dsn, '@');
        if ($hostStart === false) {
            return $dsn; // Keine Zugangsdaten enthalten, z. B. "null://null".
        }

        $credentials = substr($dsn, 0, $hostStart);
        $rest = substr($dsn, $hostStart);

        $schemeEnd = strpos($credentials, '://');
        $passwordStart = strrpos($credentials, ':');
        if ($schemeEnd === false || $passwordStart === false || $passwordStart <= $schemeEnd + 2) {
            // Kein erkennbares Passwort – dann sicherheitshalber alles.
            return ($schemeEnd === false ? '' : substr($credentials, 0, $schemeEnd + 3)).'***'.$rest;
        }

        return substr($credentials, 0, $passwordStart).':***'.$rest;
    }
}
