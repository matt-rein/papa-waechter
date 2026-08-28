<?php

namespace App\Command;

use App\Entity\AlertState;
use App\Entity\Watchee;
use App\Repository\WatcheeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Legt eine überwachte Person an (oder zeigt/erneuert deren Check-in-Link).
 * Der ausgegebene Link kommt auf Papas iPad als Homescreen-Icon.
 */
#[AsCommand(
    name: 'app:watchee:create',
    description: 'Legt eine überwachte Person an und zeigt den Check-in-Link.',
)]
class WatcheeCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WatcheeRepository $watchees,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('timezone', null, InputOption::VALUE_REQUIRED, 'Zeitzone', 'Europe/Berlin');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Überwachte Person anlegen');

        $name = (string) $io->ask('Name der Person (z. B. Papa)');
        $timezone = (string) $input->getOption('timezone');

        if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $io->error(sprintf('Unbekannte Zeitzone "%s".', $timezone));

            return Command::FAILURE;
        }

        $watchee = new Watchee();
        $watchee->setName($name);
        $watchee->setTimezone($timezone);
        $watchee->setCheckinToken(bin2hex(random_bytes(24)));

        $state = new AlertState();
        $state->setWatchee($watchee);
        $watchee->setAlertState($state);

        $this->em->persist($watchee);
        $this->em->persist($state);
        $this->em->flush();

        $link = $this->urlGenerator->generate(
            'app_checkin_show',
            ['token' => $watchee->getCheckinToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $io->success(sprintf('"%s" wurde angelegt.', $name));
        $io->section('Check-in-Link für das iPad');
        $io->writeln($link);
        $io->note([
            'Diesen Link auf Papas iPad in Safari öffnen und über das Teilen-Menü',
            '„Zum Home-Bildschirm" hinzufügen. Der Link enthält das geheime Token –',
            'also vertraulich behandeln. Die Basis-Adresse stammt aus DEFAULT_URI in .env.',
        ]);

        return Command::SUCCESS;
    }
}
