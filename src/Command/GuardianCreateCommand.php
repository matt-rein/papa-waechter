<?php

namespace App\Command;

use App\Entity\Guardian;
use App\Repository\GuardianRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Legt einen Wächter (Login für Dashboard/Alarme) an – für den produktiven
 * Einsatz gedacht, ohne die Datenbank zu leeren.
 */
#[AsCommand(
    name: 'app:guardian:create',
    description: 'Legt einen Wächter (Dashboard-Login, Alarm-Empfänger) an.',
)]
class GuardianCreateCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GuardianRepository $guardians,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Neuen Wächter anlegen');

        $email = (string) $io->ask('E-Mail-Adresse');
        if ($this->guardians->findOneBy(['email' => $email]) !== null) {
            $io->error('Diese E-Mail ist bereits vergeben.');

            return Command::FAILURE;
        }

        $name = (string) $io->ask('Name');
        $password = (string) $io->askHidden('Passwort (Eingabe versteckt)');
        $receivesAlerts = $io->confirm('Soll diese Person Alarm-Mails erhalten?', true);

        $guardian = new Guardian();
        $guardian->setEmail($email);
        $guardian->setName($name);
        $guardian->setReceivesAlerts($receivesAlerts);

        $errors = $this->validator->validate($guardian);
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $io->error($error->getPropertyPath().': '.$error->getMessage());
            }

            return Command::FAILURE;
        }

        if (strlen($password) < 8) {
            $io->error('Bitte ein Passwort mit mindestens 8 Zeichen wählen.');

            return Command::FAILURE;
        }

        $guardian->setPassword($this->hasher->hashPassword($guardian, $password));

        $this->em->persist($guardian);
        $this->em->flush();

        $io->success(sprintf('Wächter "%s" (%s) wurde angelegt.', $name, $email));

        return Command::SUCCESS;
    }
}
