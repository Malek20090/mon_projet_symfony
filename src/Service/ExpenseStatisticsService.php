<?php

namespace App\Service;

use App\Entity\Expense;

class ExpenseStatisticsService
{
    /**
     * @param Expense[] $expenses
     * @param string $referenceMonth YYYY-MM
     * @return array{
     *   months: array<int, array{month: string, total: float, count: int, average: float, top_category: string}>,
     *   available_months: string[],
     *   selected_month: string,
     *   current_month_total: float,
     *   previous_month_total: float,
     *   evolution_percent: float,
     *   average_monthly_spend: float,
     *   highest_month: array{month: string, total: float},
     *   recommendations: string[]
     * }
     */
    public function build(array $expenses, string $referenceMonth): array
    {
        $monthlyData = [];

        foreach ($expenses as $expense) {
            $date = $expense->getExpenseDate();
            if (!$date) {
                continue;
            }

            $monthKey = $date->format('Y-m');
            $amount = (float) ($expense->getAmount() ?? 0.0);
            $category = (string) ($expense->getCategory() ?? 'Other');

            if (!isset($monthlyData[$monthKey])) {
                $monthlyData[$monthKey] = [
                    'total' => 0.0,
                    'count' => 0,
                    'categories' => [],
                ];
            }

            $monthlyData[$monthKey]['total'] += $amount;
            $monthlyData[$monthKey]['count']++;
            $monthlyData[$monthKey]['categories'][$category] = ($monthlyData[$monthKey]['categories'][$category] ?? 0.0) + $amount;
        }

        ksort($monthlyData);

        $months = [];
        $monthlyTotals = [];
        foreach ($monthlyData as $month => $data) {
            arsort($data['categories']);
            $topCategory = (string) (array_key_first($data['categories']) ?? 'N/A');
            $total = (float) $data['total'];
            $count = (int) $data['count'];
            $average = $count > 0 ? ($total / $count) : 0.0;

            $months[] = [
                'month' => $month,
                'total' => round($total, 2),
                'count' => $count,
                'average' => round($average, 2),
                'top_category' => $topCategory,
            ];
            $monthlyTotals[$month] = $total;
        }

        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $referenceMonth)) {
            $referenceMonth = (new \DateTimeImmutable('first day of this month'))->format('Y-m');
        }

        $currentMonth = $referenceMonth;
        $previousMonth = \DateTimeImmutable::createFromFormat('Y-m-d', $referenceMonth . '-01')
            ?->modify('-1 month')
            ->format('Y-m') ?? (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        $currentMonthTotal = (float) ($monthlyTotals[$currentMonth] ?? 0.0);
        $previousMonthTotal = (float) ($monthlyTotals[$previousMonth] ?? 0.0);

        $evolutionPercent = 0.0;
        if ($previousMonthTotal > 0) {
            $evolutionPercent = (($currentMonthTotal - $previousMonthTotal) / $previousMonthTotal) * 100.0;
        }

        $averageMonthlySpend = 0.0;
        if (count($monthlyTotals) > 0) {
            $averageMonthlySpend = array_sum($monthlyTotals) / count($monthlyTotals);
        }

        $highestMonthKey = 'N/A';
        $highestMonthTotal = 0.0;
        if ($monthlyTotals !== []) {
            arsort($monthlyTotals);
            $highestMonthKey = (string) array_key_first($monthlyTotals);
            $highestMonthTotal = (float) ($monthlyTotals[$highestMonthKey] ?? 0.0);
        }

        return [
            'months' => array_reverse($months),
            'available_months' => array_reverse(array_keys($monthlyTotals)),
            'selected_month' => $currentMonth,
            'current_month_total' => round($currentMonthTotal, 2),
            'previous_month_total' => round($previousMonthTotal, 2),
            'evolution_percent' => round($evolutionPercent, 2),
            'average_monthly_spend' => round($averageMonthlySpend, 2),
            'highest_month' => [
                'month' => $highestMonthKey,
                'total' => round($highestMonthTotal, 2),
            ],
            'recommendations' => $this->buildRecommendations($months, $evolutionPercent, $averageMonthlySpend, $currentMonthTotal, $currentMonth),
        ];
    }

    /**
     * @param array<int, array{month: string, total: float, count: int, average: float, top_category: string}> $months
     * @return string[]
     */
    private function buildRecommendations(
        array $months,
        float $evolutionPercent,
        float $averageMonthlySpend,
        float $currentMonthTotal,
        string $selectedMonth
    ): array {
        $recommendations = [];

        if ($months === []) {
            return ['Add some expense records to generate monthly statistics and tailored recommendations.'];
        }

        if ($evolutionPercent > 10) {
            $recommendations[] = 'Your spending increased notably vs last month. Set a monthly cap and track top categories weekly.';
        } elseif ($evolutionPercent < -10) {
            $recommendations[] = 'Good improvement vs last month. Keep this trend by keeping fixed limits per category.';
        } else {
            $recommendations[] = 'Your monthly spending is relatively stable. Focus on small reductions in your top category.';
        }

        if ($averageMonthlySpend > 0 && $currentMonthTotal > ($averageMonthlySpend * 1.15)) {
            $recommendations[] = 'Current month is above your usual average. Delay non-essential purchases to rebalance.';
        }

        $topSelectedCategory = 'N/A';
        foreach ($months as $monthStat) {
            if (($monthStat['month'] ?? '') === $selectedMonth) {
                $topSelectedCategory = (string) ($monthStat['top_category'] ?? 'N/A');
                break;
            }
        }

        if ($topSelectedCategory !== 'N/A') {
            $recommendations[] = sprintf('Top category in %s is %s. Consider a dedicated budget envelope for it.', $selectedMonth, $topSelectedCategory);
        }

        return array_values(array_unique($recommendations));
    }
}
