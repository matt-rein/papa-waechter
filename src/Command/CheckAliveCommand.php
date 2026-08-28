<?php

namespace App\Command;

use App\Entity\AlertState;
use App\Repository\WatcheeRepository;
use App\Service\AlertNotifier;
use App\Service\MonitoringStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prüft, ob eine überwachte Person zu lange kein Lebenszeichen gegeben hat,
 * und benachrichtigt in dem Fall die Wächter. Für den Betrieb per Cron gedacht
 * (z. B. alle 15 Minuten).
 */
#[AsCommand(
    name: 'app:check-alive',
    description: 'Prüft die Lebenszeichen und alarmiert bei Überfälligkeit.',
)]
class CheckAliveCommand extends Command
{
    public function __construct(
        private readonly WatcheeRepository $watchees,
        private readonly MonitoringStatus $monitoring,
        private readonly AlertNotifier $notifier,
        private readonly EntityManagerInterface $em,
        private readonly int $alertReminderHours,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur prüfen, keine Mails senden, keine Änderungen speichern.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $now = new \DateTimeImmutable('now');

        $watchees = $this->watchees->findAll();
        if ($watchees === []) {
            $io->warning('Keine überwachte Person angelegt – nichts zu prüfen.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($watchees as $watchee) {
            $status = $this->monitoring->for($watchee, $now);

            // AlertState sicherstellen.
            $state = $watchee->getAlertState();
            if (!$state instanceof AlertState) {
                $state = new AlertState();
                $state->setWatchee($watchee);
                $watchee->setAlertState($state);
                $this->em->persist($state);
            }

            $action = 'OK';

            if ($status['overdue']) {
                if (!$state->isActive()) {
                    // Erstmaliger Alarm.
                    $state->setActive(true);
                    $state->setFirstTriggeredAt($now);
                    if (!$dryRun) {
                        $this->notifier->notifyOverdue($status, false);
                        $state->setLastNotifiedAt($now);
                    }
                    $action = 'ALARM ausgelöst';
                } else {
                    // Alarm läuft bereits – ggf. erneut erinnern.
                    $lastNotified = $state->getLastNotifiedAt();
                    $reminderDue = $lastNotified === null
                        || ($now->getTimestamp() - $lastNotified->getTimestamp()) >= $this->alertReminderHours * 3600;

                    if ($reminderDue) {
                        if (!$dryRun) {
                            $this->notifier->notifyOverdue($status, true);
                            $state->setLastNotifiedAt($now);
                        }
                        $action = 'Erinnerung gesendet';
                    } else {
                        $action = 'Alarm aktiv (noch keine Erinnerung fällig)';
                    }
                }
            } elseif ($state->isActive()) {
                // Sicherheitsnetz: Person wieder wohlauf (Entwarnung lief bereits
                // beim Check-in), Zustand hier aufräumen.
                $state->setActive(false);
                $state->setFirstTriggeredAt(null);
                $state->setLastNotifiedAt(null);
                $action = 'Alarm aufgelöst';
            }

            $rows[] = [
                $watchee->getName(),
                strtoupper($status['level']),
                $status['hoursSince'] === null ? 'nie' : sprintf('%.1f h', $status['hoursSince']),
                $action,
            ];
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->table(['Person', 'Status', 'Letztes Zeichen', 'Aktion'], $rows);

        if ($dryRun) {
            $io->note('Testlauf (--dry-run): Es wurden keine Mails gesendet und nichts gespeichert.');
        }

        return Command::SUCCESS;
    }
}
