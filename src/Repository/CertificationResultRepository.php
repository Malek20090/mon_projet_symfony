<?php

namespace App\Repository;

use App\Entity\CertificationResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CertificationResult>
 */
class CertificationResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CertificationResult::class);
    }

    public function findAllResults(int $limit = 200): array
    {
        return $this->findBy([], ['date' => 'DESC'], max(1, $limit));
    }

    public function findByUser(string $email): array
    {
        return $this->findBy(['userEmail' => $email], ['date' => 'DESC']);
    }

    public function getStats(): array
    {
        $total = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();
        $passed = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.passed = :passed')
            ->setParameter('passed', true)
            ->getQuery()
            ->getSingleScalarResult();
        $averageScore = (float) $this->createQueryBuilder('r')
            ->select('COALESCE(AVG(r.percentage), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
            'averageScore' => (int) round($averageScore),
        ];
    }
}
