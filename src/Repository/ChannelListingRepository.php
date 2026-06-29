<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChannelListing;
use App\Enum\ChannelType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ChannelListing>
 */
class ChannelListingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChannelListing::class);
    }

    /**
     * @return array<string, int>
     */
    public function countByChannel(): array
    {
        $rows = $this->createQueryBuilder('listing')
            ->select('listing.channel AS channel, COUNT(listing.id) AS total')
            ->groupBy('listing.channel')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $channel = ChannelType::from((string) $row['channel']);
            $counts[$channel->label()] = (int) $row['total'];
        }

        return $counts;
    }
}
