<?php

namespace App\Repository;

use App\Entity\Expense;
use App\Entity\Revenue;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Expense>
 */
class ExpenseRepository extends ServiceEntityRepository
{
    private const ALLOWED_ORDER_FIELDS = ['id', 'amount', 'category', 'expenseDate', 'description'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Expense::class);
    }

    /**
     * Return expenses for a user, including legacy rows where expense.user is null
     * but linked revenue belongs to the user.
     *
     * @return Expense[]
     */
    public function findForUser(User $user, array $orderBy = ['expenseDate' => 'DESC', 'id' => 'DESC']): array
    {
        $qb = $this->createQueryBuilder('e')
            ->leftJoin('e.revenue', 'r')
            ->addSelect('r')
            ->andWhere('(e.user = :user OR (e.user IS NULL AND r.user = :user))')
            ->setParameter('user', $user);

        foreach ($orderBy as $field => $direction) {
            $field = in_array($field, self::ALLOWED_ORDER_FIELDS, true) ? $field : 'expenseDate';
            $dir = strtoupper((string) $direction) === 'ASC' ? 'ASC' : 'DESC';
            $qb->addOrderBy('e.' . $field, $dir);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Sort expenses by two criteria.
     *
     * @param string $firstOrderBy  First field (id, amount, category, expenseDate, description)
     * @param string $firstDir      ASC or DESC
     * @param string $secondOrderBy Second field (same allowed values)
     * @param string $secondDir     ASC or DESC
     * @return Expense[]
     */
    public function sortByTwoCriteria(
        string $firstOrderBy = 'expenseDate',
        string $firstDir = 'DESC',
        string $secondOrderBy = 'amount',
        string $secondDir = 'DESC'
    ): array {
        $firstOrderBy = \in_array($firstOrderBy, self::ALLOWED_ORDER_FIELDS, true) ? $firstOrderBy : 'expenseDate';
        $secondOrderBy = \in_array($secondOrderBy, self::ALLOWED_ORDER_FIELDS, true) ? $secondOrderBy : 'amount';
        $firstDir = strtoupper($firstDir) === 'ASC' ? 'ASC' : 'DESC';
        $secondDir = strtoupper($secondDir) === 'ASC' ? 'ASC' : 'DESC';

        return $this->createQueryBuilder('e')
            ->orderBy('e.' . $firstOrderBy, $firstDir)
            ->addOrderBy('e.' . $secondOrderBy, $secondDir)
            ->getQuery()
            ->getResult();
    }

    /**
     * Search expenses by term (description, category), optional filters (category, amount range, revenue).
     *
     * @return Expense[]
     */
    public function search(
        ?string $term = null,
        ?string $category = null,
        ?float $minAmount = null,
        ?float $maxAmount = null,
        ?Revenue $revenue = null
    ): array {
        $qb = $this->createQueryBuilder('e');

        if ($term !== null && $term !== '') {
            $qb->andWhere('e.description LIKE :term OR e.category LIKE :term')
                ->setParameter('term', '%' . $term . '%');
        }
        if ($category !== null && $category !== '') {
            $qb->andWhere('e.category = :category')
                ->setParameter('category', $category);
        }
        if ($minAmount !== null) {
            $qb->andWhere('e.amount >= :minAmount')
                ->setParameter('minAmount', $minAmount);
        }
        if ($maxAmount !== null) {
            $qb->andWhere('e.amount <= :maxAmount')
                ->setParameter('maxAmount', $maxAmount);
        }
        if ($revenue !== null) {
            $qb->andWhere('e.revenue = :revenue')
                ->setParameter('revenue', $revenue);
        }

        $qb->orderBy('e.expenseDate', 'DESC')->addOrderBy('e.id', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get expense history with optional date range and revenue filter.
     *
     * @param \DateTimeInterface|null $from
     * @param \DateTimeInterface|null $to
     * @param int|null                $limit
     * @return Expense[]
     */
    public function history(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?Revenue $revenue = null,
        ?int $limit = null
    ): array {
        $qb = $this->createQueryBuilder('e');

        if ($from !== null) {
            $qb->andWhere('e.expenseDate >= :from')
                ->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('e.expenseDate <= :to')
                ->setParameter('to', $to);
        }
        if ($revenue !== null) {
            $qb->andWhere('e.revenue = :revenue')
                ->setParameter('revenue', $revenue);
        }

        $qb->orderBy('e.expenseDate', 'DESC')->addOrderBy('e.id', 'DESC');

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Return expense totals grouped by category.
     *
     * @return array<int, array{category: string, total: float}>
     */
    public function getTotalsByCategory(?User $user = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->select('COALESCE(NULLIF(TRIM(e.category), \'\'), :fallback) AS category')
            ->addSelect('SUM(e.amount) AS total')
            ->leftJoin('e.revenue', 'r')
            ->setParameter('fallback', 'Other')
            ->groupBy('category')
            ->orderBy('total', 'DESC');

        if ($user !== null) {
            $qb->andWhere('(e.user = :user OR (e.user IS NULL AND r.user = :user))')
                ->setParameter('user', $user);
        }

        $rows = $qb->getQuery()->getArrayResult();

        return array_values(array_map(
            static fn (array $row): array => [
                'category' => (string) ($row['category'] ?? 'Other'),
                'total' => (float) ($row['total'] ?? 0.0),
            ],
            $rows
        ));
    }

    /**
     * Detect the dominant category if it represents more than 40% of total expenses.
     */
    public function detectOverspendingCategory(User $user): ?string
    {
        $totalsByCategory = $this->getTotalsByCategory($user);
        if ($totalsByCategory === []) {
            return null;
        }

        $totalExpenses = array_sum(array_map(
            static fn (array $row): float => (float) ($row['total'] ?? 0.0),
            $totalsByCategory
        ));

        if ($totalExpenses <= 0.0) {
            return null;
        }

        $topCategory = (string) ($totalsByCategory[0]['category'] ?? '');
        $topAmount = (float) ($totalsByCategory[0]['total'] ?? 0.0);
        $share = $topAmount / $totalExpenses;

        return $share > 0.40 ? $topCategory : null;
    }

    /**
     * Return statistical metrics for expense amounts.
     *
     * @return array{average: float, stddev: float, count: int}
     */
    public function getExpenseStats(?User $user = null, ?string $category = null, ?int $excludeId = null): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = <<<SQL
            SELECT
                AVG(e.amount) AS avg_amount,
                AVG(e.amount * e.amount) AS avg_sq_amount,
                COUNT(*) AS cnt
            FROM expense e
            WHERE 1 = 1
        SQL;

        $params = [];
        if ($user !== null) {
            $sql .= ' AND e.user_id = :userId';
            $params['userId'] = $user->getId();
        }
        if ($category !== null && trim($category) !== '') {
            $sql .= ' AND e.category = :category';
            $params['category'] = $category;
        }
        if ($excludeId !== null) {
            $sql .= ' AND e.id <> :excludeId';
            $params['excludeId'] = $excludeId;
        }

        $row = $conn->executeQuery($sql, $params)->fetchAssociative();
        if (!$row) {
            return ['average' => 0.0, 'stddev' => 0.0, 'count' => 0];
        }

        $avg = (float) ($row['avg_amount'] ?? 0.0);
        $avgSq = (float) ($row['avg_sq_amount'] ?? 0.0);
        $count = (int) ($row['cnt'] ?? 0);

        // Variance = E[x^2] - (E[x])^2. Clamp to avoid negatives from floating error.
        $variance = max(0.0, $avgSq - ($avg * $avg));
        $stddev = sqrt($variance);

        return ['average' => $avg, 'stddev' => $stddev, 'count' => $count];
    }
}
