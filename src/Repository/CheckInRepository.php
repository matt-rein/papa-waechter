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
     * Der jüngste Check-in einer Person – maßgeblich für die Alarmprüfung.
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
     * Eine Seite des Protokolls, neueste zuerst.
     *
     * @param ?\DateTimeImmutable $since Nur Einträge ab diesem Zeitpunkt, null für alle
     *
     * @return CheckIn[]
     */
    public function findPage(Watchee $watchee, int $offset, int $limit, ?\DateTimeImmutable $since = null): array
    {
        $qb = $this->pageQuery($watchee, $since)
            ->orderBy('c.occurredAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $qb->getQuery()->getResult();
    }

    public function countFor(Watchee $watchee, ?\DateTimeImmutable $since = null): int
    {
        return (int) $this->pageQuery($watchee, $since)
            ->select('COUNT(c.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Der letzte Check-in *vor* einem Zeitpunkt.
     *
     * Für die Spalte "Abstand zur vorherigen": der Vorgänger des ältesten
     * Eintrags einer Seite steht erst auf der nächsten Seite. Ohne diese
     * Abfrage bliebe ausgerechnet die unterste Zeile jeder Seite ohne Wert –
     * und die Lücken sind der eigentliche Grund für das Protokoll.
     */
    public function findLastBefore(Watchee $watchee, \DateTimeImmutable $before): ?CheckIn
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.watchee = :w')
            ->andWhere('c.occurredAt < :before')
            ->setParameter('w', $watchee)
            ->setParameter('before', $before)
            ->orderBy('c.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function pageQuery(Watchee $watchee, ?\DateTimeImmutable $since): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.watchee = :w')
            ->setParameter('w', $watchee);

        if ($since !== null) {
            $qb->andWhere('c.occurredAt >= :since')->setParameter('since', $since);
        }

        return $qb;
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
