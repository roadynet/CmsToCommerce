<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ExternalSyncJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ExternalSyncJob>
 */
final class ExternalSyncJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ExternalSyncJob::class);
    }

    /**
     * @return list<ExternalSyncJob>
     */
    public function findDueJobs(\DateTimeImmutable $now): array
    {
        $jobs = $this->createQueryBuilder('job')
            ->andWhere('job.enabled = :enabled')
            ->setParameter('enabled', true)
            ->orderBy('job.updatedAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $jobs,
            static fn (ExternalSyncJob $job): bool => $job->isDue($now),
        ));
    }
}
