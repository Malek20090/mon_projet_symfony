<?php

namespace App\Repository;

use App\Entity\RecurringTransactionRule;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringTransactionRule>
 */
class RecurringTransactionRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringTransactionRule::class);
    }

    /**
     * @return RecurringTransactionRule[]
     */
    public function findDueActive(\DateTimeInterface $onOrBefore): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.isActive = :active')
            ->andWhere('r.nextRunAt IS NOT NULL')
            ->andWhere('r.nextRunAt <= :dueDate')
            ->setParameter('active', true)
            ->setParameter('dueDate', $onOrBefore)
            ->orderBy('r.nextRunAt', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function existsForUserSignature(User $user, string $signature): bool
    {
        $count = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.user = :user')
            ->andWhere('r.signature = :signature')
            ->setParameter('user', $user)
            ->setParameter('signature', $signature)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}

