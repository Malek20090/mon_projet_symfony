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
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<SQL
            SELECT e.category AS category, SUM(e.amount) AS total
            FROM expense e
        SQL;

        $params = [];
        if ($user !== null) {
            $sql .= ' WHERE e.user_id = :userId';
            $params['userId'] = $user->getId();
        }

        $sql .= ' GROUP BY e.category ORDER BY total DESC';
        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'category' => (string) $row['category'],
                'total' => (float) $row['total'],
            ],
            $rows
        );
    }
}
