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
