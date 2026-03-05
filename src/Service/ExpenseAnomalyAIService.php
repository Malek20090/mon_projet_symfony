<?php

namespace App\Service;

use App\Entity\Expense;
use App\Repository\ExpenseRepository;

class ExpenseAnomalyAIService
{
    public function __construct(
        private ExpenseRepository $expenseRepository,
        private float $sensitivity = 2.5,
        private float $minAmount = 400.0
    ) {
    }

    /**
     * Determine if an expense is anomalous using overall + category baselines.
     */
    public function isAnomalousExpense(Expense $expense): bool
    {
        if ($expense->getUser() === null) {
            return false;
        }

        $category = $this->expenseRepository->getExpenseStats(
            $expense->getUser(),
            $expense->getCategory(),
            $expense->getId()
        );

        return $this->isAboveThreshold($expense, $category);
    }

    /**
     * Return an anomaly score (z-score). Higher = more anomalous.
     */
    public function anomalyScore(Expense $expense): float
    {
        if ($expense->getUser() === null) {
            return 0.0;
        }

        $category = $this->expenseRepository->getExpenseStats(
            $expense->getUser(),
            $expense->getCategory(),
            $expense->getId()
        );

        return $this->zScore($expense, $category);
    }

    /**
     * @param array{average: float, stddev: float, count: int} $stats
     */
    private function isAboveThreshold(Expense $expense, array $stats): bool
    {
        if ((float) $expense->getAmount() < $this->minAmount) {
            return false;
        }
        if (($stats['count'] ?? 0) < 2 || ($stats['stddev'] ?? 0.0) <= 0.0) {
            return false;
        }

        $avg = (float) $stats['average'];
        $stddev = (float) $stats['stddev'];
        $threshold = $avg + ($this->sensitivity * $stddev);

        return (float) $expense->getAmount() > $threshold;
    }

    /**
     * @param array{average: float, stddev: float, count: int} $stats
     */
    private function zScore(Expense $expense, array $stats): float
    {
        if ((float) $expense->getAmount() < $this->minAmount) {
            return 0.0;
        }
        if (($stats['count'] ?? 0) < 2 || ($stats['stddev'] ?? 0.0) <= 0.0) {
            return 0.0;
        }

        $avg = (float) $stats['average'];
        $stddev = (float) $stats['stddev'];

        return ((float) $expense->getAmount() - $avg) / $stddev;
    }
}
