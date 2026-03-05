<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Expense;
use InvalidArgumentException;

final class ExpenseManager
{
    public function validate(Expense $expense): bool
    {
        $amount = $expense->getAmount();

        if ($amount === null || $amount < 0) {
            throw new InvalidArgumentException('Expense amount must be >= 0.');
        }

        if (trim((string) $expense->getCategory()) === '') {
            throw new InvalidArgumentException('Expense category is required.');
        }

        $revenue = $expense->getRevenue();
        if ($revenue === null) {
            throw new InvalidArgumentException('Linked revenue is required.');
        }

        if ($amount > $revenue->getAmount()) {
            throw new InvalidArgumentException('Expense amount cannot exceed revenue amount.');
        }

        return true;
    }
}
