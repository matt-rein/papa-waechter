<?php

namespace App\Repository;

use App\Entity\Guardian;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<Guardian>
 */
class GuardianRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Guardian::class);
    }

    /**
     * Wird von der Security-Schicht genutzt, um veraltete Passwort-Hashes
     * transparent auf ein neueres Verfahren zu heben.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof Guardian) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Alle Wächter, die Alarm-Mails erhalten sollen.
     *
     * @return Guardian[]
     */
    public function findAlertRecipients(): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.receivesAlerts = :yes')
            ->setParameter('yes', true)
            ->getQuery()
            ->getResult();
    }
}
