<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Enum\ProductStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('product')
            ->select('product.status AS status, COUNT(product.id) AS total')
            ->groupBy('product.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach (ProductStatus::cases() as $status) {
            $counts[$status->label()] = 0;
        }

        foreach ($rows as $row) {
            $status = ProductStatus::from((string) $row['status']);
            $counts[$status->label()] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * @return list<Product>
     */
    public function findLatest(int $limit = 10): array
    {
        return $this->createQueryBuilder('product')
            ->orderBy('product.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneBySourceReference(?string $cmsSystem, string $externalReference): ?Product
    {
        $builder = $this->createQueryBuilder('product')
            ->innerJoin('product.sources', 'source')
            ->andWhere('source.externalReference = :externalReference')
            ->setParameter('externalReference', $externalReference)
            ->setMaxResults(1);

        $cmsSystem = trim((string) $cmsSystem);
        if ($cmsSystem !== '') {
            $builder
                ->andWhere('source.cmsSystem = :cmsSystem')
                ->setParameter('cmsSystem', $cmsSystem);
        }

        return $builder->getQuery()->getOneOrNullResult();
    }

    public function findOneByVariantSku(string $sku): ?Product
    {
        return $this->createQueryBuilder('product')
            ->innerJoin('product.variants', 'variant')
            ->andWhere('variant.sku = :sku')
            ->setParameter('sku', $sku)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
