<?php

namespace App\Repository;

use App\Entity\SavingAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SavingAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavingAccount::class);
    }

    public function findOneByUser(User $user): ?SavingAccount
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.user = :u')
            ->setParameter('u', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
