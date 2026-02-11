<?php

namespace App\Repository;

use App\Entity\Quiz;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Quiz>
 */
class QuizRepository extends ServiceEntityRepository
{
    public const SORT_QUESTION = 'question';
    public const SORT_POINTS = 'pointsValeur';
    public const SORT_REPONSE = 'reponseCorrecte';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Quiz::class);
    }

    /**
     * Recherche et tri des quiz (sans critère sur l'id).
     *
     * @param string|null $search Mot-clé (question, reponseCorrecte)
     * @param string      $sortBy Champ de tri : question, pointsValeur, reponseCorrecte
     * @param string      $order  ASC ou DESC
     * @return Quiz[]
     */
    public function searchAndSort(?string $search = null, string $sortBy = self::SORT_QUESTION, string $order = 'ASC'): array
    {
        $qb = $this->createQueryBuilder('q');

        if ($search !== null && $search !== '') {
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('q.question', ':search'),
                    $qb->expr()->like('q.reponseCorrecte', ':search')
                )
            )->setParameter('search', '%' . $search . '%');
        }

        $allowedSort = [self::SORT_QUESTION, self::SORT_POINTS, self::SORT_REPONSE];
        if (!\in_array($sortBy, $allowedSort, true)) {
            $sortBy = self::SORT_QUESTION;
        }
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';

        $qb->orderBy('q.' . $sortBy, $order);

        return $qb->getQuery()->getResult();
    }
}
