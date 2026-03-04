<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserBehaviorProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserBehaviorProfile>
 */
class UserBehaviorProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserBehaviorProfile::class);
    }

    public function findOneByUser(User $user): ?UserBehaviorProfile
    {
        return $this->findOneBy(['user' => $user]);
    }
}

