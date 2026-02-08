<?php

namespace App\Repository;

use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }
    // src/Repository/TransactionRepository.php
public function queryWithFilters(
    ?string $type = null,
    ?string $from = null,
    ?string $to = null,
    ?string $userName = null
) {
    $qb = $this->createQueryBuilder('t')
        ->leftJoin('t.user', 'u')
        ->addSelect('u');

    if ($type) {
        $qb->andWhere('t.type = :type')
           ->setParameter('type', $type);
    }

    if ($from) {
        $qb->andWhere('t.date >= :from')
           ->setParameter('from', new \DateTime($from));
    }

    if ($to) {
        $qb->andWhere('t.date <= :to')
           ->setParameter('to', new \DateTime($to));
    }

    if (!empty($userName)) {
        $qb->andWhere('LOWER(u.nom) LIKE :name')
           ->setParameter('name', '%' . strtolower($userName) . '%');
    }

    return $qb->orderBy('t.date', 'DESC')->getQuery();
}

public function sumByType(string $type): float
{
    return (float) $this->createQueryBuilder('t')
        ->select('COALESCE(SUM(t.amount), 0)')
        ->andWhere('t.type = :type')
        ->setParameter('type', $type)
        ->getQuery()
        ->getSingleScalarResult();
}
public function getMonthlyStats(): array
{
    $qb = $this->createQueryBuilder('t')
        ->select(
            "MONTH(t.date) AS month,
             SUM(CASE WHEN t.type = 'SAVING' THEN t.montant ELSE 0 END) AS savings,
             SUM(CASE WHEN t.type = 'EXPENSE' THEN t.montant ELSE 0 END) AS expenses"
        )
        ->groupBy('month')
        ->orderBy('month', 'ASC');

    return $qb->getQuery()->getResult();
}

    //    /**
    //     * @return Transaction[] Returns an array of Transaction objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('t.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Transaction
    //    {
    //        return $this->createQueryBuilder('t')
    //            ->andWhere('t.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
