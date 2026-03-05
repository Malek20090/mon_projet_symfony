<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Expense;
use App\Entity\Revenue;
use App\Service\ExpenseManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExpenseManagerTest extends TestCase
{
    public function testValidExpense(): void
    {
        $manager = new ExpenseManager();
        $expense = $this->buildExpense(200.0, 'Food', 1000.0);

        self::assertTrue($manager->validate($expense));
    }

    public function testNegativeAmountThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $manager = new ExpenseManager();
        $expense = $this->buildExpense(-10.0, 'Food', 1000.0);

        $manager->validate($expense);
    }

    public function testAmountGreaterThanRevenueThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $manager = new ExpenseManager();
        $expense = $this->buildExpense(1200.0, 'Food', 1000.0);

        $manager->validate($expense);
    }

    private function buildExpense(float $amount, string $category, float $revenueAmount): Expense
    {
        $revenue = (new Revenue())
            ->setAmount($revenueAmount)
            ->setType('FIXE')
            ->setReceivedAt(new \DateTime('2026-03-01'));

        return (new Expense())
            ->setAmount($amount)
            ->setCategory($category)
            ->setExpenseDate(new \DateTime('2026-03-02'))
            ->setRevenue($revenue);
    }
}
