<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PublicationRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicationRun>
 */
class PublicationRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicationRun::class);
    }

    /**
     * @return list<PublicationRun>
     */
    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('run')
            ->orderBy('run.startedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
