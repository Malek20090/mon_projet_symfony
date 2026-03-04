<?php

namespace App\Repository;

use App\Entity\Reclamation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reclamation>
 */
class ReclamationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reclamation::class);
    }

    /**
     * @return Reclamation[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Reclamation[]
     */
    public function findForAdmin(?string $status = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')
            ->addSelect('u')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC');

        if ($status !== null && $status !== '') {
            $qb->andWhere('r.status = :status')->setParameter('status', strtoupper(trim($status)));
        }

        return $qb->getQuery()->getResult();
    }

    public function countBlockedBadWordsNotHandled(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status = :status')
            ->andWhere('r.adminResponder IS NULL')
            ->setParameter('status', Reclamation::STATUS_BLOCKED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Reclamation[]
     */
    public function findRecentBlockedBadWords(int $limit = 5): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.user', 'u')
            ->addSelect('u')
            ->andWhere('r.status = :status')
            ->andWhere('r.adminResponder IS NULL')
            ->setParameter('status', Reclamation::STATUS_BLOCKED)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }
}
