<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     
     *
     * @return User[]
     */
    public function findForAdminIndex(
        ?string $search = null,
        ?string $role = null,
        string $sortBy = 'nom',
        string $order = 'ASC'
    ): array {
        $qb = $this->createQueryBuilder('u');

        if ($search !== null && trim($search) !== '') {
            $value = '%' . mb_strtolower(trim($search)) . '%';
            $qb->andWhere('LOWER(u.nom) LIKE :q OR LOWER(u.email) LIKE :q')
                ->setParameter('q', $value);
        }

        if ($role !== null && $role !== '') {
            if ($role === 'ROLE_USER_ONLY') {
                $qb->andWhere('u.roles NOT LIKE :adminRole')
                    ->andWhere('u.roles NOT LIKE :salaryRole')
                    ->andWhere('u.roles NOT LIKE :studentRole')
                    ->setParameter('adminRole', '%ROLE_ADMIN%')
                    ->setParameter('salaryRole', '%ROLE_SALARY%')
                    ->setParameter('studentRole', '%ROLE_ETUDIANT%');
            } else {
                $qb->andWhere('u.roles LIKE :rolePattern')
                    ->setParameter('rolePattern', '%' . $role . '%');
            }
        }

        $allowedSort = [
            'id' => 'u.id',
            'nom' => 'u.nom',
            'email' => 'u.email',
            'solde' => 'u.soldeTotal',
            'date' => 'u.dateInscription',
        ];
        $sortExpr = $allowedSort[$sortBy] ?? $allowedSort['nom'];
        $direction = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $qb->orderBy($sortExpr, $direction)
            ->addOrderBy('u.id', 'DESC');

        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return User[] Returns an array of User objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('u')
    //            ->andWhere('u.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('u.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

//    public function findOneBySomeField($value): ?User
//    {
//        return $this->createQueryBuilder('u')
//            ->andWhere('u.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }

    /**
     * Find users by role
     * @return User[]
     */
    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.roles LIKE :role')
            ->setParameter('role', '%"' . $role . '"%')
            ->orderBy('u.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
