<?php

namespace App\Repository;

use App\Entity\Imprevus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Imprevus>
 */
class ImprevusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Imprevus::class);
    }

    /**
     * Imprevus par type (POSITIF ou NEGATIF) pour la liste "Risques (-)" / "Opportunites (+)".
     * Imprévus par type (POSITIF ou NEGATIF) pour la liste "Risques (-)" / "Opportunités (+)".
     * @return Imprevus[]
     */
    public function findByType(string $type): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.type = :type')
            ->setParameter('type', $type)
            ->orderBy('i.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search and sort Imprevus for admin.
     *
     * @param string $search Search term
     * @param string $sort Field to sort by
     * @param string $order Order direction (asc/desc)
     * @return Imprevus[]
     */
    public function findBySearchAndSort(string $search = '', string $sort = 'id', string $order = 'desc'): array
    {
        $qb = $this->createQueryBuilder('i');

        // Apply search on multiple fields
        if (!empty($search)) {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('i.titre', ':search'),
                    $qb->expr()->like('i.type', ':search'),
                    $qb->expr()->like('i.messageEducatif', ':search')
                )
            );
            $qb->setParameter('search', '%' . $search . '%');
        }

        // Apply sorting
        $validSortFields = ['id', 'titre', 'type', 'budget'];
        if (!in_array($sort, $validSortFields)) {
            $sort = 'id';
        }
        $order = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $qb->orderBy('i.' . $sort, $order);

        return $qb->getQuery()->getResult();
    }

    //    /**
    //     * @return Imprevus[] Returns an array of Imprevus objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('i.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Imprevus
    //    {
    //        return $this->createQueryBuilder('i')
    //            ->andWhere('i.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
