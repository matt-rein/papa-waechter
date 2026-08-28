<?php

namespace App\Command;

use App\Repository\GuardianRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Setzt das Passwort eines Wächters neu – für den Fall, dass der Zugang
 * vergessen wurde. Ohne E-Mail werden alle vorhandenen Wächter aufgelistet.
 */
#[AsCommand(
    name: 'app:guardian:password',
    description: 'Setzt das Passwort eines Wächters neu.',
)]
class GuardianPasswordCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GuardianRepository $guardians,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'E-Mail des Wächters');
        $this->addOption('generate', null, InputOption::VALUE_NONE, 'Zufälliges Passwort erzeugen und anzeigen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Passwort zurücksetzen');

        $all = $this->guardians->findBy([], ['email' => 'ASC']);
        if ($all === []) {
            $io->error('Es ist noch kein Wächter angelegt. Bitte "app:guardian:create" nutzen.');

            return Command::FAILURE;
        }

        $email = $input->getArgument('email');
        if ($email === null) {
            $emails = array_map(static fn ($g) => $g->getEmail(), $all);
            $email = (string) $io->choice('Für welchen Wächter?', $emails, $emails[0]);
        }

        $guardian = $this->guardians->findOneBy(['email' => $email]);
        if ($guardian === null) {
            $io->error(sprintf('Kein Wächter mit der E-Mail "%s" gefunden.', $email));

            return Command::FAILURE;
        }

        if ($input->getOption('generate')) {
            // 18 Bytes -> 24 Zeichen base64, ohne verwechselbare Sonderzeichen.
            $password = rtrim(strtr(base64_encode(random_bytes(18)), '+/', 'Ab'), '=');
        } else {
            $password = (string) $io->askHidden('Neues Passwort (Eingabe versteckt)');
            $repeat = (string) $io->askHidden('Neues Passwort wiederholen');
            if ($password !== $repeat) {
                $io->error('Die beiden Eingaben stimmen nicht überein.');

                return Command::FAILURE;
            }
        }

        if (strlen($password) < 8) {
            $io->error('Bitte ein Passwort mit mindestens 8 Zeichen wählen.');

            return Command::FAILURE;
        }

        $guardian->setPassword($this->hasher->hashPassword($guardian, $password));
        $this->em->flush();

        $io->success(sprintf('Passwort für "%s" (%s) wurde neu gesetzt.', $guardian->getName(), $guardian->getEmail()));

        if ($input->getOption('generate')) {
            $io->writeln(' Neues Passwort: <info>'.$password.'</info>');
            $io->warning('Bitte notieren und nach dem ersten Login ändern – es wird nicht noch einmal angezeigt.');
        }

        return Command::SUCCESS;
    }
}
