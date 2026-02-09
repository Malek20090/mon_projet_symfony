<?php

namespace App\Repository;

use App\Entity\CasRelles;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CasRelles>
 */
class CasRellesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CasRelles::class);
    }

    /**
     * Historique des cas reels pour un utilisateur (pour la page simulation aleas).
     * @return CasRelles[]
     */
    public function findByUserOrderByDateDesc(User $user): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->setParameter('user', $user)
            ->orderBy('c.dateEffet', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find and sort CasRelles for admin.
     *
     * @param string $search Search term
     * @param string $sort Field to sort by
     * @param string $order Order direction (asc/desc)
     * @param string $filter Filter by resultat (EN_ATTENTE, VALIDE, REFUSE, all)
     * @return CasRelles[]
     */
    public function findBySearchAndSort(string $search = '', string $sort = 'id', string $order = 'desc', string $filter = 'all'): array
    {
        $qb = $this->createQueryBuilder('c');

        // Apply search on multiple fields
        if (!empty($search)) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('c.titre', ':search'),
                    $qb->expr()->like('c.description', ':search'),
                    $qb->expr()->like('c.type', ':search'),
                    $qb->expr()->like('c.solution', ':search'),
                    $qb->expr()->like('c.resultat', ':search')
                )
            );
            $qb->setParameter('search', '%' . $search . '%');
        }

        // Apply filter by resultat
        if ($filter !== 'all' && !empty($filter)) {
            $qb->andWhere('c.resultat = :filter');
            $qb->setParameter('filter', $filter);
        }

        // Apply sorting
        $validSortFields = ['id', 'titre', 'type', 'montant', 'solution', 'resultat', 'dateEffet'];
        if (!in_array($sort, $validSortFields)) {
            $sort = 'id';
        }
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $qb->orderBy('c.' . $sort, $order);

        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return CasRelles[] Returns an array of CasRelles objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('c.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?CasRelles
    //    {
    //        return $this->createQueryBuilder('c')
    //            ->andWhere('c.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
