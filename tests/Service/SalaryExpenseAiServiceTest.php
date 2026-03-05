<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Expense;
use App\Entity\Revenue;
use App\Service\SalaryExpenseAiService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class SalaryExpenseAiServiceTest extends TestCase
{
    public function testBuildInsightsReturnsAdvancedFieldsWithLocalFallback(): void
    {
        $service = $this->buildService();

        $primaryRevenue = $this->buildRevenue(3200.0, '2026-02-01');
        $secondaryRevenue = $this->buildRevenue(450.0, '2026-02-15');

        $expenses = [
            $this->buildExpense(780.0, 'Rent', '2026-01-05', $primaryRevenue),
            $this->buildExpense(420.0, 'Food', '2026-01-16', $primaryRevenue),
            $this->buildExpense(980.0, 'Rent', '2026-02-03', $primaryRevenue),
            $this->buildExpense(510.0, 'Transport', '2026-02-18', $secondaryRevenue),
        ];

        $insights = $service->buildInsights([$primaryRevenue, $secondaryRevenue], $expenses);

        self::assertSame('local', $insights['ai_source']);
        self::assertArrayHasKey('forecast_30_days', $insights);
        self::assertArrayHasKey('cashflow_health', $insights);
        self::assertArrayHasKey('smart_budgets', $insights);
        self::assertArrayHasKey('priority_actions', $insights);
        self::assertGreaterThan(0.0, $insights['forecast_30_days']['expected_expenses']);
        self::assertContains($insights['trend']['direction'], ['up', 'down', 'stable']);
        self::assertNotSame('', trim($insights['ai_summary']));
    }

    public function testBuildInsightsDetectsUpwardTrendFromMonthlySeries(): void
    {
        $service = $this->buildService();
        $revenue = $this->buildRevenue(4000.0, '2026-03-01');

        $expenses = [
            $this->buildExpense(100.0, 'Food', '2025-12-05', $revenue),
            $this->buildExpense(200.0, 'Food', '2026-01-10', $revenue),
            $this->buildExpense(320.0, 'Food', '2026-02-14', $revenue),
        ];

        $insights = $service->buildInsights([$revenue], $expenses);

        self::assertSame('up', $insights['trend']['direction']);
        self::assertGreaterThan(0.0, $insights['trend']['delta_percent']);
    }

    public function testBuildMonthlyExpenseAdviceFallsBackLocally(): void
    {
        $service = $this->buildService();

        $months = [
            [
                'month' => '2026-02',
                'total' => 1300.0,
                'count' => 6,
                'average' => 216.67,
                'top_category' => 'Rent',
            ],
        ];

        $advice = $service->buildMonthlyExpenseAdvice($months, '2026-02');

        self::assertSame('local', $advice['source']);
        self::assertArrayHasKey('2026-02', $advice['by_month']);
        self::assertNotSame('', trim($advice['by_month']['2026-02']));
    }

    private function buildService(): SalaryExpenseAiService
    {
        return new SalaryExpenseAiService(new MockHttpClient(), null);
    }

    private function buildRevenue(float $amount, string $date): Revenue
    {
        return (new Revenue())
            ->setAmount($amount)
            ->setType('FIXE')
            ->setReceivedAt(new \DateTime($date));
    }

    private function buildExpense(float $amount, string $category, string $date, Revenue $revenue): Expense
    {
        return (new Expense())
            ->setAmount($amount)
            ->setCategory($category)
            ->setExpenseDate(new \DateTime($date))
            ->setRevenue($revenue);
    }
}
