<?php

namespace App\Repository;

use App\Entity\FinancialGoal;
use App\Entity\SavingAccount;
use App\Entity\User;
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

    /**
     * Advanced goal scoring used by Savings & Goals dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findGoalHealthByAccount(SavingAccount $account): array
    {
        $today = new \DateTimeImmutable('today');

        return $this->createQueryBuilder('g')
            ->select([
                'g.id',
                'g.nom',
                'g.montantCible',
                'g.montantActuel',
                'g.priorite',
                'g.dateLimite',
                '(CASE WHEN g.montantCible > 0 THEN (g.montantActuel / g.montantCible) ELSE 0 END) AS progressRatio',
                '(CASE WHEN g.montantCible > g.montantActuel THEN (g.montantCible - g.montantActuel) ELSE 0 END) AS remainingAmount',
                '(CASE WHEN g.dateLimite IS NULL THEN 999999 ELSE DATE_DIFF(g.dateLimite, :today) END) AS daysLeft',
                '(CASE
                    WHEN g.dateLimite IS NULL OR DATE_DIFF(g.dateLimite, :today) <= 0 THEN 0
                    ELSE (g.montantCible - g.montantActuel) / DATE_DIFF(g.dateLimite, :today)
                END) AS dailyNeeded',
                '(CASE
                    WHEN g.dateLimite IS NULL OR DATE_DIFF(g.dateLimite, :today) <= 0 THEN 0
                    ELSE ((g.montantCible - g.montantActuel) / DATE_DIFF(g.dateLimite, :today)) * 30
                END) AS monthlyNeeded',
                '(
                    (COALESCE(g.priorite, 3) * 8)
                    + ((1 - (CASE WHEN g.montantCible > 0 THEN (g.montantActuel / g.montantCible) ELSE 0 END)) * 60)
                    + (CASE
                        WHEN g.dateLimite IS NULL THEN 0
                        WHEN DATE_DIFF(g.dateLimite, :today) <= 0 THEN 40
                        WHEN DATE_DIFF(g.dateLimite, :today) <= 7 THEN 30
                        WHEN DATE_DIFF(g.dateLimite, :today) <= 30 THEN 15
                        ELSE 5
                    END)
                ) AS urgencyScore',
            ])
            ->andWhere('g.savingAccount = :acc')
            ->setParameter('acc', $account)
            ->setParameter('today', $today)
            ->orderBy('urgencyScore', 'DESC')
            ->addOrderBy('dailyNeeded', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Advanced goal scoring used by Savings & Goals dashboard (account id variant).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findGoalHealthByAccountId(int $accountId): array
    {
        if ($accountId <= 0) {
            return [];
        }

        $today = new \DateTimeImmutable('today');

        return $this->createQueryBuilder('g')
            ->select([
                'g.id',
                'g.nom',
                'g.montantCible',
                'g.montantActuel',
                'g.priorite',
                'g.dateLimite',
                '(CASE WHEN g.montantCible > 0 THEN (g.montantActuel / g.montantCible) ELSE 0 END) AS progressRatio',
                '(CASE WHEN g.montantCible > g.montantActuel THEN (g.montantCible - g.montantActuel) ELSE 0 END) AS remainingAmount',
                '(CASE WHEN g.dateLimite IS NULL THEN 999999 ELSE DATE_DIFF(g.dateLimite, :today) END) AS daysLeft',
                '(CASE
                    WHEN g.dateLimite IS NULL OR DATE_DIFF(g.dateLimite, :today) <= 0 THEN 0
                    ELSE (g.montantCible - g.montantActuel) / DATE_DIFF(g.dateLimite, :today)
                END) AS dailyNeeded',
                '(CASE
                    WHEN g.dateLimite IS NULL OR DATE_DIFF(g.dateLimite, :today) <= 0 THEN 0
                    ELSE ((g.montantCible - g.montantActuel) / DATE_DIFF(g.dateLimite, :today)) * 30
                END) AS monthlyNeeded',
                '(
                    (COALESCE(g.priorite, 3) * 8)
                    + ((1 - (CASE WHEN g.montantCible > 0 THEN (g.montantActuel / g.montantCible) ELSE 0 END)) * 60)
                    + (CASE
                        WHEN g.dateLimite IS NULL THEN 0
                        WHEN DATE_DIFF(g.dateLimite, :today) <= 0 THEN 40
                        WHEN DATE_DIFF(g.dateLimite, :today) <= 7 THEN 30
                        WHEN DATE_DIFF(g.dateLimite, :today) <= 30 THEN 15
                        ELSE 5
                    END)
                ) AS urgencyScore',
            ])
            ->join('g.savingAccount', 'sa')
            ->andWhere('sa.id = :accId')
            ->setParameter('accId', $accountId)
            ->setParameter('today', $today)
            ->orderBy('urgencyScore', 'DESC')
            ->addOrderBy('dailyNeeded', 'DESC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return array{id:int,montantActuel:float}|null
     */
    public function findOwnedGoalSnapshotById(int $goalId, User $user): ?array
    {
        if ($goalId <= 0) {
            return null;
        }

        $row = $this->createQueryBuilder('g')
            ->select('g.id AS id', 'g.montantActuel AS montantActuel')
            ->join('g.savingAccount', 'sa')
            ->join('sa.user', 'u')
            ->andWhere('g.id = :goalId')
            ->andWhere('u.id = :userId')
            ->setParameter('goalId', $goalId)
            ->setParameter('userId', (int) $user->getId())
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        if ($row === []) {
            return null;
        }

        return [
            'id' => (int) ($row[0]['id'] ?? 0),
            'montantActuel' => (float) ($row[0]['montantActuel'] ?? 0.0),
        ];
    }

    /**
     * Dashboard list for Savings/Goals tab using QueryBuilder/DQL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findDashboardRowsByAccountId(int $accountId, ?string $q, string $sort): array
    {
        if ($accountId <= 0) {
            return [];
        }

        $qb = $this->createQueryBuilder('g')
            ->select([
                'g.id AS id',
                'g.nom AS nom',
                'g.montantCible AS montant_cible',
                'g.montantActuel AS montant_actuel',
                'g.dateLimite AS date_limite',
                'g.priorite AS priorite',
            ])
            ->join('g.savingAccount', 'sa')
            ->andWhere('sa.id = :accId')
            ->setParameter('accId', $accountId);

        $q = trim((string) $q);
        if ($q !== '') {
            $orParts = [
                'LOWER(g.nom) LIKE :qtxt',
                'STR(g.priorite) LIKE :qlike',
                'STR(g.montantCible) LIKE :qlike',
                'STR(g.montantActuel) LIKE :qlike',
            ];
            $asDate = \DateTimeImmutable::createFromFormat('Y-m-d', $q);
            if ($asDate instanceof \DateTimeImmutable) {
                $orParts[] = 'g.dateLimite = :qdate';
                $qb->setParameter('qdate', \DateTime::createFromImmutable($asDate->setTime(0, 0, 0)));
            }

            $qb->andWhere($qb->expr()->orX(...$orParts))
                ->setParameter('qtxt', '%' . mb_strtolower($q) . '%')
                ->setParameter('qlike', '%' . $q . '%');
        }

        switch ($sort) {
            case 'deadline_desc':
                $qb->addOrderBy('g.dateLimite', 'DESC');
                break;
            case 'priority_desc':
                $qb->addOrderBy('g.priorite', 'DESC');
                break;
            case 'priority_asc':
                $qb->addOrderBy('g.priorite', 'ASC');
                break;
            case 'progress_desc':
                $qb->addSelect('(CASE WHEN g.montantCible > 0 THEN (g.montantActuel / g.montantCible) ELSE 0 END) AS HIDDEN progress_order')
                    ->addOrderBy('progress_order', 'DESC');
                break;
            case 'progress_asc':
                $qb->addSelect('(CASE WHEN g.montantCible > 0 THEN (g.montantActuel / g.montantCible) ELSE 0 END) AS HIDDEN progress_order')
                    ->addOrderBy('progress_order', 'ASC');
                break;
            case 'name_asc':
                $qb->addOrderBy('g.nom', 'ASC');
                break;
            case 'name_desc':
                $qb->addOrderBy('g.nom', 'DESC');
                break;
            case 'id_asc':
                $qb->addOrderBy('g.id', 'ASC');
                break;
            case 'id_desc':
                $qb->addOrderBy('g.id', 'DESC');
                break;
            case 'deadline_asc':
            default:
                $qb->addOrderBy('g.dateLimite', 'ASC');
                break;
        }

        $rows = $qb->getQuery()->getArrayResult();

        // Keep backward-compatible shape with legacy SQL rows used by SavingsController.
        return array_map(static function (array $row): array {
            $date = $row['date_limite'] ?? null;
            if ($date instanceof \DateTimeInterface) {
                $row['date_limite'] = $date->format('Y-m-d');
            } elseif ($date === null) {
                $row['date_limite'] = null;
            } else {
                $row['date_limite'] = (string) $date;
            }

            return $row;
        }, $rows);
    }
}
