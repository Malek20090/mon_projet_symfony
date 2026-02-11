<?php

namespace App\Repository;

use App\Entity\Cours;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cours>
 */
class CoursRepository extends ServiceEntityRepository
{
    public const SORT_TITRE = 'titre';
    public const SORT_TYPE_MEDIA = 'typeMedia';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cours::class);
    }

    /**
     * Recherche et tri des cours (sans critère sur l'id).
     *
     * @param string|null $search Mot-clé (titre, typeMedia, contenuTexte)
     * @param string      $sortBy Champ de tri : titre, typeMedia
     * @param string      $order  ASC ou DESC
     * @return Cours[]
     */
    public function searchAndSort(?string $search = null, string $sortBy = self::SORT_TITRE, string $order = 'ASC'): array
    {
        $qb = $this->createQueryBuilder('c');

        if ($search !== null && $search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('c.titre', ':search'),
                    $qb->expr()->like('c.typeMedia', ':search'),
                    $qb->expr()->like('c.contenuTexte', ':search')
                )
            )->setParameter('search', '%' . $search . '%');
        }

        $allowedSort = [self::SORT_TITRE, self::SORT_TYPE_MEDIA];
        if (!\in_array($sortBy, $allowedSort, true)) {
            $sortBy = self::SORT_TITRE;
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $qb->orderBy('c.' . $sortBy, $order);

        return $qb->getQuery()->getResult();
    }
}
