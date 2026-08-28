<?php

namespace App\Repository;

use App\Entity\CheckIn;
use App\Entity\Watchee;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CheckIn>
 */
class CheckInRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckIn::class);
    }

    /**
     * Der jüngste Check-in einer Person – maßgeblich für die 24h-Prüfung.
     */
    public function findLatestFor(Watchee $watchee): ?CheckIn
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.watchee = :w')
            ->setParameter('w', $watchee)
            ->orderBy('c.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Die letzten N Check-ins für die Verlaufsanzeige im Dashboard.
     *
     * @return CheckIn[]
     */
    public function findRecentFor(Watchee $watchee, int $limit = 30): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.watchee = :w')
            ->setParameter('w', $watchee)
            ->orderBy('c.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
