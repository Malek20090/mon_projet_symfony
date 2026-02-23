<?php

namespace App\Repository;

use App\Entity\Revenue;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Revenue>
 */
class RevenueRepository extends ServiceEntityRepository
{
    private const ALLOWED_ORDER_FIELDS = ['id', 'amount', 'type', 'receivedAt', 'createdAt', 'description'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Revenue::class);
    }

    /**
     * Sort revenues by two criteria.
     *
     * @param string $firstOrderBy  First field (id, amount, type, receivedAt, createdAt, description)
     * @param string $firstDir      ASC or DESC
     * @param string $secondOrderBy Second field (same allowed values)
     * @param string $secondDir     ASC or DESC
     * @return Revenue[]
     */
    public function sortByTwoCriteria(
        string $firstOrderBy = 'receivedAt',
        string $firstDir = 'DESC',
        string $secondOrderBy = 'amount',
        string $secondDir = 'DESC'
    ): array {
        $firstOrderBy = \in_array($firstOrderBy, self::ALLOWED_ORDER_FIELDS, true) ? $firstOrderBy : 'receivedAt';
        $secondOrderBy = \in_array($secondOrderBy, self::ALLOWED_ORDER_FIELDS, true) ? $secondOrderBy : 'amount';
        $firstDir = strtoupper($firstDir) === 'ASC' ? 'ASC' : 'DESC';
        $secondDir = strtoupper($secondDir) === 'ASC' ? 'ASC' : 'DESC';

        return $this->createQueryBuilder('r')
            ->orderBy('r.' . $firstOrderBy, $firstDir)
            ->addOrderBy('r.' . $secondOrderBy, $secondDir)
            ->getQuery()
            ->getResult();
    }

    /**
     * Search revenues by term (description, type), optional filters (type, amount range, user).
     *
     * @return Revenue[]
     */
    public function search(
        ?string $term = null,
        ?string $type = null,
        ?float $minAmount = null,
        ?float $maxAmount = null,
        ?User $user = null
    ): array {
        $qb = $this->createQueryBuilder('r');

        if ($term !== null && $term !== '') {
            $qb->andWhere('r.description LIKE :term OR r.type LIKE :term')
                ->setParameter('term', '%' . $term . '%');
        }
        if ($type !== null && $type !== '') {
            $qb->andWhere('r.type = :type')
                ->setParameter('type', $type);
        }
        if ($minAmount !== null) {
            $qb->andWhere('r.amount >= :minAmount')
                ->setParameter('minAmount', $minAmount);
        }
        if ($maxAmount !== null) {
            $qb->andWhere('r.amount <= :maxAmount')
                ->setParameter('maxAmount', $maxAmount);
        }
        if ($user !== null) {
            $qb->andWhere('r.user = :user')
                ->setParameter('user', $user);
        }

        $qb->orderBy('r.receivedAt', 'DESC')->addOrderBy('r.id', 'DESC');

        return $qb->getQuery()->getResult();
    }

    /**
     * Get revenue history with optional date range and user filter.
     *
     * @param \DateTimeInterface|null $from
     * @param \DateTimeInterface|null $to
     * @param int|null                $limit
     * @return Revenue[]
     */
    public function history(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?User $user = null,
        ?int $limit = null
    ): array {
        $qb = $this->createQueryBuilder('r');

        if ($from !== null) {
            $qb->andWhere('r.receivedAt >= :from')
                ->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('r.receivedAt <= :to')
                ->setParameter('to', $to);
        }
        if ($user !== null) {
            $qb->andWhere('r.user = :user')
                ->setParameter('user', $user);
        }

        $qb->orderBy('r.receivedAt', 'DESC')->addOrderBy('r.id', 'DESC');

        if ($limit !== null && $limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Return monthly revenue totals grouped by "YYYY-MM".
     *
     * @return array<int, array{month: string, total: float}>
     */
    public function getMonthlyTotalsByMonth(?User $user = null, int $months = 12): array
    {
        $months = max(1, $months);
        $fromDate = (new \DateTimeImmutable('first day of this month'))
            ->modify(sprintf('-%d months', $months - 1))
            ->setTime(0, 0, 0)
            ->format('Y-m-d');

        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<SQL
            SELECT DATE_FORMAT(r.received_at, '%Y-%m') AS month, SUM(r.amount) AS total
            FROM revenue r
            WHERE r.received_at >= :fromDate
        SQL;

        $params = ['fromDate' => $fromDate];

        if ($user !== null) {
            $sql .= ' AND r.user_id = :userId';
            $params['userId'] = $user->getId();
        }

        $sql .= ' GROUP BY month ORDER BY month ASC';
        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        return array_map(
            static fn (array $row): array => [
                'month' => (string) $row['month'],
                'total' => (float) $row['total'],
            ],
            $rows
        );
    }
}
