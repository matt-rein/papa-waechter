<?php

namespace App\DataFixtures;

use App\Entity\AlertState;
use App\Entity\Guardian;
use App\Entity\Watchee;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Demo-/Testdaten für die lokale Entwicklung.
 *
 * ACHTUNG: `doctrine:fixtures:load` LEERT die Datenbank. Nicht in Produktion
 * verwenden – dort die Daten stattdessen mit den `app:guardian:create`- und
 * `app:watchee:create`-Commands anlegen.
 */
class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // --- Überwachte Person (Papa) ---
        $papa = new Watchee();
        $papa->setName('Papa');
        $papa->setTimezone('Europe/Berlin');
        // Fester Demo-Token, damit der Link lokal stabil ist.
        $papa->setCheckinToken('demo-token-papa-0000000000000000');
        $manager->persist($papa);

        $state = new AlertState();
        $state->setWatchee($papa);
        $papa->setAlertState($state);
        $manager->persist($state);

        // --- Wächter (du + Schwester) ---
        $guardians = [
            ['mre@reinhardt-online.de', 'Matthias', true],
            ['schwester@example.com', 'Schwester', true],
        ];

        foreach ($guardians as [$email, $name, $alerts]) {
            $g = new Guardian();
            $g->setEmail($email);
            $g->setName($name);
            $g->setReceivesAlerts($alerts);
            $g->setPassword($this->hasher->hashPassword($g, 'changeme'));
            $manager->persist($g);
        }

        $manager->flush();
    }
}
