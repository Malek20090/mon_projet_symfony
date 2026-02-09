<?php

namespace App\Repository;

use App\Entity\FinancialGoal;
use App\Entity\SavingAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class FinancialGoalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FinancialGoal::class);
    }

    /**
     * Search + sort + filters for FrontOffice goals
     */
    public function searchForSavingAccount(
        SavingAccount $account,
        ?string $q,
        ?string $filter,
        ?string $sort
    ): array {
        $qb = $this->createQueryBuilder('g')
            ->andWhere('g.savingAccount = :acc')
            ->setParameter('acc', $account);

        // Filter chips
        if ($filter === 'high') {
            $qb->andWhere('g.priorite >= 4');
        }
        if ($filter === 'near') {
            $qb->andWhere('g.dateLimite IS NOT NULL')
               ->andWhere('g.dateLimite <= :nearDate')
               ->setParameter('nearDate', (new \DateTime('+14 days'))->setTime(0,0,0));
        }

        // Search (name + priority + deadline + amounts)
        if ($q !== null && trim($q) !== '') {
            $q = trim($q);

            // If numeric -> also match amounts/priority
            if (is_numeric($q)) {
                $num = (float)$q;
                $qb->andWhere(
                    'LOWER(g.nom) LIKE :txt
                     OR g.priorite = :prio
                     OR g.montantCible = :num
                     OR g.montantActuel = :num'
                )
                ->setParameter('txt', '%'.mb_strtolower($q).'%')
                ->setParameter('prio', (int)$q)
                ->setParameter('num', $num);
            } else {
                // try date parse "2026-02-25"
                $date = \DateTime::createFromFormat('Y-m-d', $q);
                if ($date instanceof \DateTime) {
                    $qb->andWhere('g.dateLimite = :d')->setParameter('d', $date);
                } else {
                    $qb->andWhere('LOWER(g.nom) LIKE :txt')
                       ->setParameter('txt', '%'.mb_strtolower($q).'%');
                }
            }
        }

        // Sort
        switch ($sort) {
            case 'deadline_asc':
                $qb->addOrderBy('g.dateLimite', 'ASC')->addOrderBy('g.id', 'DESC');
                break;

            case 'name_asc':
                $qb->addOrderBy('g.nom', 'ASC');
                break;

            case 'progress_desc':
                // (montantActuel / montantCible)
                $qb->addSelect('(CASE WHEN g.montantCible > 0 THEN (g.montantActuel / g.montantCible) ELSE 0 END) AS HIDDEN prog')
                   ->addOrderBy('prog', 'DESC')
                   ->addOrderBy('g.priorite', 'DESC');
                break;

            case 'priority_desc':
            default:
                $qb->addOrderBy('g.priorite', 'DESC')
                   ->addOrderBy('g.dateLimite', 'ASC')
                   ->addOrderBy('g.id', 'DESC');
                break;
        }

        return $qb->getQuery()->getResult();
    }
}
