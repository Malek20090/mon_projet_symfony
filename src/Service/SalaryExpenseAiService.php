<?php

namespace App\Service;

use App\Entity\Expense;
use App\Entity\Revenue;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SalaryExpenseAiService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $groqApiKey = null,
        private readonly string $groqModel = 'llama-3.1-8b-instant',
        private readonly string $groqApiUrl = 'https://api.groq.com/openai/v1/chat/completions'
    ) {
    }

    /**
     * @param Revenue[] $revenues
     * @param Expense[] $expenses
     *
     * @return array{
     *   total_income: float,
     *   total_expenses: float,
     *   net_balance: float,
     *   savings_rate: float,
     *   burn_rate: float,
     *   average_expense: float,
     *   category_breakdown: array<string, float>,
     *   anomalies: array<int, array{id: int|null, amount: float, category: string, date: string}>,
     *   recommendations: string[],
     *   monthly_series: array<int, array{month: string, total: float, count: int}>,
     *   trend: array{direction: string, delta_percent: float, last_month: string|null, previous_month: string|null},
     *   forecast_30_days: array{expected_expenses: float, expected_net: float, confidence: string},
     *   cashflow_health: array{score: int, level: string, coverage_ratio: float|null, forecast_pressure: float|null},
     *   smart_budgets: array<int, array{category: string, current: float, target: float, delta: float, share_percent: float}>,
     *   priority_actions: string[],
     *   ai_source: string,
     *   ai_summary: string
     * }
     */
    public function buildInsights(array $revenues, array $expenses): array
    {
        $totalIncome = array_sum(array_map(static fn (Revenue $r): float => (float) $r->getAmount(), $revenues));
        $totalExpenses = array_sum(array_map(static fn (Expense $e): float => (float) ($e->getAmount() ?? 0.0), $expenses));
        $netBalance = $totalIncome - $totalExpenses;
        $savingsRate = $totalIncome > 0 ? (($netBalance / $totalIncome) * 100.0) : 0.0;
        $burnRate = $totalIncome > 0 ? (($totalExpenses / $totalIncome) * 100.0) : 0.0;
        $averageExpense = count($expenses) > 0 ? ($totalExpenses / count($expenses)) : 0.0;

        $categoryBreakdown = [];
        foreach ($expenses as $expense) {
            $category = (string) ($expense->getCategory() ?? 'Other');
            $categoryBreakdown[$category] = ($categoryBreakdown[$category] ?? 0.0) + (float) ($expense->getAmount() ?? 0.0);
        }
        arsort($categoryBreakdown);

        $monthlySeries = $this->buildMonthlySeries($expenses);
        $trend = $this->buildTrend($monthlySeries);
        $anomalies = $this->detectAnomalies($expenses, $averageExpense);
        $forecast30Days = $this->buildForecast30Days($expenses, $trend, $totalIncome);
        $cashflowHealth = $this->buildCashflowHealth(
            $totalIncome,
            $totalExpenses,
            $burnRate,
            $savingsRate,
            count($anomalies),
            $trend,
            $forecast30Days
        );
        $smartBudgets = $this->buildSmartBudgets($categoryBreakdown, $totalExpenses);

        $recommendations = $this->buildRecommendations($totalIncome, $totalExpenses, $savingsRate, $categoryBreakdown, $anomalies);
        $priorityActions = $this->buildPriorityActions(
            $savingsRate,
            $burnRate,
            $smartBudgets,
            $anomalies,
            $trend,
            $forecast30Days
        );

        $insights = [
            'total_income' => round($totalIncome, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_balance' => round($netBalance, 2),
            'savings_rate' => round($savingsRate, 2),
            'burn_rate' => round($burnRate, 2),
            'average_expense' => round($averageExpense, 2),
            'category_breakdown' => array_map(static fn (float $v): float => round($v, 2), $categoryBreakdown),
            'anomalies' => $anomalies,
            'recommendations' => $recommendations,
            'monthly_series' => $monthlySeries,
            'trend' => $trend,
            'forecast_30_days' => $forecast30Days,
            'cashflow_health' => $cashflowHealth,
            'smart_budgets' => $smartBudgets,
            'priority_actions' => $priorityActions,
            'ai_source' => 'local',
            'ai_summary' => '',
        ];

        $aiNarrative = $this->generateAiNarrative($insights);
        if ($aiNarrative !== null) {
            $insights['ai_summary'] = $aiNarrative['summary'];
            if ($aiNarrative['priority_actions'] !== []) {
                $insights['priority_actions'] = $aiNarrative['priority_actions'];
            }
            $insights['ai_source'] = 'groq';
        } else {
            $insights['ai_summary'] = $this->generateLocalSummary($insights);
        }

        return $insights;
    }

    /**
     * @param Expense[] $expenses
     * @return array<int, array{month: string, total: float, count: int}>
     */
    private function buildMonthlySeries(array $expenses): array
    {
        /** @var array<string, array{total: float, count: int}> $seriesMap */
        $seriesMap = [];
        foreach ($expenses as $expense) {
            $date = $expense->getExpenseDate();
            if ($date === null) {
                continue;
            }

            $month = $date->format('Y-m');
            if (!isset($seriesMap[$month])) {
                $seriesMap[$month] = ['total' => 0.0, 'count' => 0];
            }

            $seriesMap[$month]['total'] += (float) ($expense->getAmount() ?? 0.0);
            $seriesMap[$month]['count']++;
        }

        ksort($seriesMap);

        $series = [];
        foreach ($seriesMap as $month => $row) {
            $series[] = [
                'month' => $month,
                'total' => round((float) $row['total'], 2),
                'count' => (int) $row['count'],
            ];
        }

        return $series;
    }

    /**
     * @param array<int, array{month: string, total: float, count: int}> $monthlySeries
     * @return array{direction: string, delta_percent: float, last_month: string|null, previous_month: string|null}
     */
    private function buildTrend(array $monthlySeries): array
    {
        $count = count($monthlySeries);
        if ($count < 2) {
            return [
                'direction' => 'stable',
                'delta_percent' => 0.0,
                'last_month' => $count === 1 ? (string) $monthlySeries[0]['month'] : null,
                'previous_month' => null,
            ];
        }

        $last = $monthlySeries[$count - 1];
        $previous = $monthlySeries[$count - 2];

        $lastTotal = (float) $last['total'];
        $previousTotal = (float) $previous['total'];
        if ($previousTotal <= 0.0) {
            $deltaPercent = $lastTotal > 0.0 ? 100.0 : 0.0;
        } else {
            $deltaPercent = (($lastTotal - $previousTotal) / $previousTotal) * 100.0;
        }

        $direction = 'stable';
        if ($deltaPercent >= 5.0) {
            $direction = 'up';
        } elseif ($deltaPercent <= -5.0) {
            $direction = 'down';
        }

        return [
            'direction' => $direction,
            'delta_percent' => round($deltaPercent, 2),
            'last_month' => (string) $last['month'],
            'previous_month' => (string) $previous['month'],
        ];
    }

    /**
     * @param Expense[] $expenses
     * @param array{direction: string, delta_percent: float, last_month: string|null, previous_month: string|null} $trend
     * @return array{expected_expenses: float, expected_net: float, confidence: string}
     */
    private function buildForecast30Days(array $expenses, array $trend, float $totalIncome): array
    {
        if ($expenses === []) {
            return [
                'expected_expenses' => 0.0,
                'expected_net' => round($totalIncome, 2),
                'confidence' => 'low',
            ];
        }

        $today = new \DateTimeImmutable('today');
        $windowStart = $today->modify('-89 days');
        $recentTotal = 0.0;
        $recentCount = 0;
        $oldestRecentDate = null;

        foreach ($expenses as $expense) {
            $date = $expense->getExpenseDate();
            if ($date === null) {
                continue;
            }
            $immutableDate = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
            if ($immutableDate < $windowStart) {
                continue;
            }

            $recentTotal += (float) ($expense->getAmount() ?? 0.0);
            $recentCount++;
            if ($oldestRecentDate === null || $immutableDate < $oldestRecentDate) {
                $oldestRecentDate = $immutableDate;
            }
        }

        if ($recentCount > 0 && $oldestRecentDate instanceof \DateTimeImmutable) {
            $days = max(1, (int) $today->diff($oldestRecentDate)->format('%a') + 1);
            $dailyAverage = $recentTotal / $days;
        } else {
            $allTotal = array_sum(array_map(static fn (Expense $expense): float => (float) ($expense->getAmount() ?? 0.0), $expenses));
            $dailyAverage = $allTotal / 30.0;
        }

        $trendDelta = (float) ($trend['delta_percent'] ?? 0.0);
        $trendFactor = 1.0 + max(-0.25, min(0.35, ($trendDelta / 100.0) * 0.55));
        $expectedExpenses = max(0.0, $dailyAverage * 30.0 * $trendFactor);
        $expectedNet = $totalIncome - $expectedExpenses;

        $confidence = 'low';
        if ($recentCount >= 20) {
            $confidence = 'high';
        } elseif ($recentCount >= 8) {
            $confidence = 'medium';
        }

        return [
            'expected_expenses' => round($expectedExpenses, 2),
            'expected_net' => round($expectedNet, 2),
            'confidence' => $confidence,
        ];
    }

    /**
     * @param array{direction: string, delta_percent: float, last_month: string|null, previous_month: string|null} $trend
     * @param array{expected_expenses: float, expected_net: float, confidence: string} $forecast30Days
     * @return array{score: int, level: string, coverage_ratio: float|null, forecast_pressure: float|null}
     */
    private function buildCashflowHealth(
        float $totalIncome,
        float $totalExpenses,
        float $burnRate,
        float $savingsRate,
        int $anomalyCount,
        array $trend,
        array $forecast30Days
    ): array {
        if ($totalIncome <= 0.0 && $totalExpenses > 0.0) {
            return [
                'score' => 12,
                'level' => 'critical',
                'coverage_ratio' => 0.0,
                'forecast_pressure' => null,
            ];
        }

        $score = 100.0;
        if ($burnRate > 70.0) {
            $score -= ($burnRate - 70.0) * 0.9;
        }
        if ($savingsRate < 20.0) {
            $score -= (20.0 - $savingsRate) * 1.2;
        }
        if ($anomalyCount > 0) {
            $score -= min(24.0, $anomalyCount * 4.5);
        }

        $trendDelta = (float) ($trend['delta_percent'] ?? 0.0);
        if ($trendDelta > 0.0) {
            $score -= min(16.0, $trendDelta * 0.5);
        }

        $forecastPressure = null;
        if ($totalIncome > 0.0) {
            $forecastExpected = (float) ($forecast30Days['expected_expenses'] ?? 0.0);
            $forecastPressure = (($forecastExpected - $totalIncome) / $totalIncome) * 100.0;
            if ($forecastPressure > 0.0) {
                $score -= min(18.0, $forecastPressure * 0.4);
            }
        }

        $normalizedScore = (int) round(max(5.0, min(100.0, $score)));
        $level = 'critical';
        if ($normalizedScore >= 75) {
            $level = 'strong';
        } elseif ($normalizedScore >= 50) {
            $level = 'watch';
        }

        return [
            'score' => $normalizedScore,
            'level' => $level,
            'coverage_ratio' => $totalExpenses > 0.0 ? round($totalIncome / $totalExpenses, 2) : null,
            'forecast_pressure' => $forecastPressure !== null ? round($forecastPressure, 2) : null,
        ];
    }

    /**
     * @param array<string, float> $categoryBreakdown
     * @return array<int, array{category: string, current: float, target: float, delta: float, share_percent: float}>
     */
    private function buildSmartBudgets(array $categoryBreakdown, float $totalExpenses): array
    {
        if ($categoryBreakdown === [] || $totalExpenses <= 0.0) {
            return [];
        }

        $budgets = [];
        foreach (array_slice($categoryBreakdown, 0, 4, true) as $category => $amount) {
            $current = (float) $amount;
            $sharePercent = ($current / $totalExpenses) * 100.0;

            $targetMultiplier = 0.98;
            if ($sharePercent >= 35.0) {
                $targetMultiplier = 0.85;
            } elseif ($sharePercent >= 20.0) {
                $targetMultiplier = 0.90;
            } elseif ($sharePercent >= 12.0) {
                $targetMultiplier = 0.95;
            }

            $target = $current * $targetMultiplier;
            $budgets[] = [
                'category' => (string) $category,
                'current' => round($current, 2),
                'target' => round($target, 2),
                'delta' => round($current - $target, 2),
                'share_percent' => round($sharePercent, 2),
            ];
        }

        return $budgets;
    }

    /**
     * @param Expense[] $expenses
     * @return array<int, array{id: int|null, amount: float, category: string, date: string}>
     */
    private function detectAnomalies(array $expenses, float $averageExpense): array
    {
        if (count($expenses) < 3 || $averageExpense <= 0) {
            return [];
        }

        $amounts = array_map(static fn (Expense $e): float => (float) ($e->getAmount() ?? 0.0), $expenses);
        $variance = 0.0;
        foreach ($amounts as $amount) {
            $variance += ($amount - $averageExpense) ** 2;
        }
        $variance /= max(count($amounts), 1);
        $stdDev = sqrt($variance);
        $threshold = $averageExpense + (1.5 * $stdDev);

        $anomalies = [];
        foreach ($expenses as $expense) {
            $amount = (float) ($expense->getAmount() ?? 0.0);
            if ($amount >= $threshold) {
                $anomalies[] = [
                    'id' => $expense->getId(),
                    'amount' => round($amount, 2),
                    'category' => (string) ($expense->getCategory() ?? 'Other'),
                    'date' => $expense->getExpenseDate()?->format('Y-m-d') ?? 'N/A',
                ];
            }
        }

        usort(
            $anomalies,
            static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']
        );

        return array_slice($anomalies, 0, 5);
    }

    /**
     * @param array<int, array{category: string, current: float, target: float, delta: float, share_percent: float}> $smartBudgets
     * @param array<int, array{id: int|null, amount: float, category: string, date: string}> $anomalies
     * @param array{direction: string, delta_percent: float, last_month: string|null, previous_month: string|null} $trend
     * @param array{expected_expenses: float, expected_net: float, confidence: string} $forecast30Days
     * @return string[]
     */
    private function buildPriorityActions(
        float $savingsRate,
        float $burnRate,
        array $smartBudgets,
        array $anomalies,
        array $trend,
        array $forecast30Days
    ): array {
        $actions = [];

        if ($savingsRate < 20.0) {
            $actions[] = 'Automate a weekly transfer to push your savings rate above 20% this month.';
        }
        if ($burnRate > 85.0) {
            $actions[] = 'Apply a 7-day freeze on optional purchases to immediately reduce your burn rate.';
        }

        foreach (array_slice($smartBudgets, 0, 2) as $budget) {
            if ((float) $budget['delta'] <= 0.0) {
                continue;
            }
            $actions[] = sprintf(
                'Reduce %s by %.2f TND next month (target %.2f TND).',
                (string) $budget['category'],
                (float) $budget['delta'],
                (float) $budget['target']
            );
        }

        if ($anomalies !== []) {
            $actions[] = 'Audit your top anomalous expenses and mark each one as one-off or recurring.';
        }

        $trendDirection = (string) ($trend['direction'] ?? 'stable');
        $trendDelta = (float) ($trend['delta_percent'] ?? 0.0);
        if ($trendDirection === 'up' && $trendDelta >= 8.0) {
            $actions[] = sprintf(
                'Expense trend increased by %.1f%%; set an approval threshold before spending above 150 TND.',
                $trendDelta
            );
        }

        if ((float) ($forecast30Days['expected_net'] ?? 0.0) < 0.0) {
            $actions[] = 'Your 30-day forecast is negative; cut variable categories first and delay non-essential purchases.';
        }

        if ($actions === []) {
            $actions[] = 'Maintain current discipline and review spending categories once per week.';
        }

        return array_slice(array_values(array_unique($actions)), 0, 5);
    }

    /**
     * @param array<string, float> $categoryBreakdown
     * @param array<int, array{id: int|null, amount: float, category: string, date: string}> $anomalies
     * @return string[]
     */
    private function buildRecommendations(
        float $totalIncome,
        float $totalExpenses,
        float $savingsRate,
        array $categoryBreakdown,
        array $anomalies
    ): array {
        $recommendations = [];

        if ($totalIncome <= 0) {
            $recommendations[] = 'Add at least one income source to compute meaningful financial guidance.';
        }

        if ($savingsRate < 20) {
            $recommendations[] = 'Target a savings rate above 20% by reducing non-essential categories first.';
        } else {
            $recommendations[] = 'Your savings trend is healthy. Keep automating monthly savings transfers.';
        }

        if ($totalIncome > 0 && ($totalExpenses / $totalIncome) > 0.8) {
            $recommendations[] = 'Your burn rate is high (above 80%). Set category caps for the next month.';
        }

        if ($categoryBreakdown !== []) {
            $topCategory = array_key_first($categoryBreakdown);
            $topAmount = (float) $categoryBreakdown[$topCategory];
            if ($totalExpenses > 0 && ($topAmount / $totalExpenses) >= 0.35) {
                $recommendations[] = sprintf(
                    '%s represents %.1f%% of your expenses; review this category for optimization.',
                    $topCategory,
                    ($topAmount / $totalExpenses) * 100
                );
            }
        }

        if ($anomalies !== []) {
            $recommendations[] = 'Review the flagged high expenses and validate if they are exceptional or recurring.';
        }

        return array_values(array_unique($recommendations));
    }

    /**
     * @param array{
     *   total_income: float,
     *   total_expenses: float,
     *   net_balance: float,
     *   savings_rate: float,
     *   burn_rate: float,
     *   average_expense: float,
     *   category_breakdown: array<string, float>,
     *   anomalies: array<int, array{id: int|null, amount: float, category: string, date: string}>,
     *   recommendations: string[],
     *   monthly_series: array<int, array{month: string, total: float, count: int}>,
     *   trend: array{direction: string, delta_percent: float, last_month: string|null, previous_month: string|null},
     *   forecast_30_days: array{expected_expenses: float, expected_net: float, confidence: string},
     *   cashflow_health: array{score: int, level: string, coverage_ratio: float|null, forecast_pressure: float|null},
     *   smart_budgets: array<int, array{category: string, current: float, target: float, delta: float, share_percent: float}>,
     *   priority_actions: string[],
     *   ai_source: string,
     *   ai_summary: string
     * } $insights
     * @return array{summary: string, priority_actions: string[]}|null
     */
    private function generateAiNarrative(array $insights): ?array
    {
        $key = trim((string) $this->groqApiKey);
        if ($key === '') {
            return null;
        }

        try {
            $prompt = json_encode(
                [
                    'task' => 'Act as a senior personal finance co-pilot. Produce advanced but concise guidance.',
                    'rules' => [
                        'Return strict JSON only.',
                        'Keep summary under 90 words.',
                        'priority_actions must contain 3 to 5 short, concrete actions.',
                        'No markdown.',
                    ],
                    'output_schema' => [
                        'summary' => 'string',
                        'priority_actions' => ['string'],
                    ],
                    'data' => [
                        'totals' => [
                            'income' => $insights['total_income'],
                            'expenses' => $insights['total_expenses'],
                            'net' => $insights['net_balance'],
                            'savings_rate' => $insights['savings_rate'],
                            'burn_rate' => $insights['burn_rate'],
                        ],
                        'trend' => $insights['trend'],
                        'forecast_30_days' => $insights['forecast_30_days'],
                        'cashflow_health' => $insights['cashflow_health'],
                        'top_categories' => array_slice($insights['category_breakdown'], 0, 4, true),
                        'smart_budgets' => $insights['smart_budgets'],
                        'anomalies' => $insights['anomalies'],
                        'baseline_actions' => $insights['priority_actions'],
                    ],
                ],
                JSON_THROW_ON_ERROR
            );
            $content = $this->requestGroqText($prompt, true);
            if ($content === '') {
                return null;
            }

            /** @var array{summary?: mixed, priority_actions?: mixed} $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $summary = trim((string) ($decoded['summary'] ?? ''));
            $actionsRaw = $decoded['priority_actions'] ?? [];

            $actions = [];
            if (is_array($actionsRaw)) {
                foreach ($actionsRaw as $action) {
                    if (!is_string($action)) {
                        continue;
                    }
                    $clean = trim($action);
                    if ($clean !== '') {
                        $actions[] = $clean;
                    }
                }
            }

            if ($summary === '' && $actions === []) {
                return null;
            }
            if ($summary === '') {
                $summary = $this->generateLocalSummary($insights);
            }

            return [
                'summary' => $summary,
                'priority_actions' => array_slice(array_values(array_unique($actions)), 0, 5),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array{
     *   total_income: float,
     *   total_expenses: float,
     *   net_balance: float,
     *   savings_rate: float,
     *   burn_rate: float,
     *   average_expense: float,
     *   category_breakdown: array<string, float>,
     *   anomalies: array<int, array{id: int|null, amount: float, category: string, date: string}>,
     *   recommendations: string[],
     *   trend: array{direction: string, delta_percent: float, last_month: string|null, previous_month: string|null},
     *   forecast_30_days: array{expected_expenses: float, expected_net: float, confidence: string},
     *   cashflow_health: array{score: int, level: string, coverage_ratio: float|null, forecast_pressure: float|null},
     *   ai_summary: string
     * } $insights
     */
    private function generateLocalSummary(array $insights): string
    {
        $topCategory = array_key_first($insights['category_breakdown']) ?? 'N/A';
        $trendDirection = (string) ($insights['trend']['direction'] ?? 'stable');
        $trendDelta = (float) ($insights['trend']['delta_percent'] ?? 0.0);
        $cashflowLevel = strtoupper((string) ($insights['cashflow_health']['level'] ?? 'watch'));
        $cashflowScore = (int) ($insights['cashflow_health']['score'] ?? 50);
        $forecastExpenses = (float) ($insights['forecast_30_days']['expected_expenses'] ?? 0.0);
        $forecastConfidence = (string) ($insights['forecast_30_days']['confidence'] ?? 'low');

        return sprintf(
            'Income %.2f TND, expenses %.2f TND, net %.2f TND. Savings %.2f%% and burn %.2f%%. Trend is %s (%.1f%%), forecast for next 30 days is %.2f TND (%s confidence). Cashflow health is %s (%d/100). Top category: %s.',
            $insights['total_income'],
            $insights['total_expenses'],
            $insights['net_balance'],
            $insights['savings_rate'],
            $insights['burn_rate'],
            $trendDirection,
            $trendDelta,
            $forecastExpenses,
            $forecastConfidence,
            $cashflowLevel,
            $cashflowScore,
            $topCategory,
        );
    }

    /**
     * Build one concise advice per month from month stats.
     *
     * @param array<int, array{month: string, total: float, count: int, average: float, top_category: string}> $months
     * @return array{by_month: array<string, string>, selected: string, source: string}
     */
    public function buildMonthlyExpenseAdvice(array $months, string $selectedMonth): array
    {
        if ($months === []) {
            return [
                'by_month' => [
                    $selectedMonth => 'No expenses recorded for this month yet. Add expenses to receive tailored advice.',
                ],
                'selected' => $selectedMonth,
                'source' => 'local',
            ];
        }

        $byMonth = $this->generateMonthlyAdviceWithAi($months);
        $source = 'ai';

        if ($byMonth === null || $byMonth === []) {
            $byMonth = $this->generateMonthlyAdviceLocally($months);
            $source = 'local';
        }

        if (!isset($byMonth[$selectedMonth])) {
            $byMonth[$selectedMonth] = 'No expenses recorded for this month yet. Add expenses to receive tailored advice.';
        }

        return [
            'by_month' => $byMonth,
            'selected' => $selectedMonth,
            'source' => $source,
        ];
    }

    /**
     * @param array<int, array{month: string, total: float, count: int, average: float, top_category: string}> $months
     * @return array<string, string>|null
     */
    private function generateMonthlyAdviceWithAi(array $months): ?array
    {
        $key = trim((string) $this->groqApiKey);
        if ($key === '') {
            return null;
        }

        try {
            $prompt = json_encode([
                'task' => 'For each month, provide one concise expense optimization advice.',
                'rules' => [
                    'One short sentence per month, max 18 words.',
                    'Be actionable and specific to month total and top category.',
                    'Output strict JSON object exactly: {"advice_by_month":{"YYYY-MM":"advice"}}',
                ],
                'months' => $months,
            ], JSON_THROW_ON_ERROR);

            $content = $this->requestGroqText($prompt, true);
            if ($content === '') {
                return null;
            }

            /** @var array{advice_by_month?: array<string, string>} $decoded */
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $map = $decoded['advice_by_month'] ?? null;
            if (!is_array($map)) {
                return null;
            }

            $clean = [];
            foreach ($map as $month => $advice) {
                if (!is_string($month) || !preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $month)) {
                    continue;
                }
                if (!is_string($advice) || trim($advice) === '') {
                    continue;
                }
                $clean[$month] = trim($advice);
            }

            return $clean !== [] ? $clean : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array{month: string, total: float, count: int, average: float, top_category: string}> $months
     * @return array<string, string>
     */
    private function generateMonthlyAdviceLocally(array $months): array
    {
        $adviceByMonth = [];

        foreach ($months as $stat) {
            $month = (string) ($stat['month'] ?? '');
            if ($month === '') {
                continue;
            }

            $topCategory = (string) ($stat['top_category'] ?? 'Other');
            $total = (float) ($stat['total'] ?? 0.0);
            $average = (float) ($stat['average'] ?? 0.0);
            $count = (int) ($stat['count'] ?? 0);

            if ($count <= 0) {
                $adviceByMonth[$month] = 'No expenses recorded. Track essentials first to establish a realistic baseline.';
                continue;
            }

            if ($average > 0 && $total > ($average * $count * 1.10)) {
                $adviceByMonth[$month] = sprintf('Reduce %s spending by 10%% and schedule non-essential purchases next month.', $topCategory);
            } else {
                $adviceByMonth[$month] = sprintf('Keep your %s budget cap and trim one optional purchase this month.', $topCategory);
            }
        }

        return $adviceByMonth;
    }

    private function requestGroqText(string $prompt, bool $jsonExpected): string
    {
        $key = trim((string) $this->groqApiKey);
        if ($key === '') {
            return '';
        }

        $payload = [
            'model' => $this->groqModel,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $jsonExpected
                        ? 'You are a finance assistant. Return valid JSON only.'
                        : 'You are a concise personal finance assistant.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'temperature' => 0.2,
        ];

        $response = $this->httpClient->request('POST', $this->groqApiUrl, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $key,
            ],
            'json' => $payload,
            'timeout' => 20,
        ]);

        if ($response->getStatusCode() >= 400) {
            return '';
        }

        $data = $response->toArray(false);
        $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

        if (!$jsonExpected) {
            return $content;
        }

        return $this->extractJsonFromText($content);
    }

    private function extractJsonFromText(string $text): string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/si', $trimmed, $match) === 1) {
            return trim((string) $match[1]);
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return trim(substr($trimmed, $start, ($end - $start) + 1));
        }

        return $trimmed;
    }
}

