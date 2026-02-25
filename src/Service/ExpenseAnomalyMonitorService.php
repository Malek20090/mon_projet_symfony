<?php

namespace App\Service;

use App\Entity\Expense;
use Psr\Log\LoggerInterface;

class ExpenseAnomalyMonitorService
{
    public function __construct(
        private ExpenseAnomalyAIService $anomalyAIService,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Trigger AI anomaly detection and log when an expense is suspicious.
     */
    public function handleNewExpense(Expense $expense): void
    {
        if (!$this->anomalyAIService->isAnomalousExpense($expense)) {
            return;
        }

        $score = $this->anomalyAIService->anomalyScore($expense);

        $this->logger->warning('Anomalous expense detected.', [
            'expense_id' => $expense->getId(),
            'user_id' => $expense->getUser()?->getId(),
            'category' => $expense->getCategory(),
            'amount' => (float) $expense->getAmount(),
            'score' => $score,
        ]);
    }

    public function isAnomalousExpense(Expense $expense): bool
    {
        return $this->anomalyAIService->isAnomalousExpense($expense);
    }
}
