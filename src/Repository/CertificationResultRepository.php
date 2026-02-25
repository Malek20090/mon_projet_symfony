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

    public function findAllResults(): array
    {
        return $this->findBy([], ['date' => 'DESC']);
    }

    public function findByUser(string $email): array
    {
        return $this->findBy(['userEmail' => $email], ['date' => 'DESC']);
    }

    public function getStats(): array
    {
        $results = $this->findAll();
        
        $total = count($results);
        $passed = count(array_filter($results, fn($r) => $r->isPassed()));
        
        return [
            'total' => $total,
            'passed' => $passed,
            'failed' => $total - $passed,
            'averageScore' => $total > 0 ? round(array_sum(array_map(fn($r) => $r->getPercentage(), $results)) / $total) : 0,
        ];
    }
}
