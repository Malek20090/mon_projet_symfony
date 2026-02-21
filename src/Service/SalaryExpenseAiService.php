<?php

namespace App\Service;

use App\Entity\Expense;
use App\Entity\Revenue;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class SalaryExpenseAiService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $openAiApiKey = null,
        private readonly string $openAiModel = 'gpt-4o-mini'
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

        $anomalies = $this->detectAnomalies($expenses, $averageExpense);
        $recommendations = $this->buildRecommendations($totalIncome, $totalExpenses, $savingsRate, $categoryBreakdown, $anomalies);

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
            'ai_summary' => '',
        ];

        $insights['ai_summary'] = $this->generateAiSummary($insights) ?? $this->generateLocalSummary($insights);

        return $insights;
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
     *   ai_summary: string
     * } $insights
     */
    private function generateAiSummary(array $insights): ?string
    {
        $key = trim((string) $this->openAiApiKey);
        if ($key === '') {
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->openAiModel,
                    'temperature' => 0.2,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a personal finance assistant. Return concise, actionable advice in plain English with max 100 words.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'totals' => [
                                    'income' => $insights['total_income'],
                                    'expenses' => $insights['total_expenses'],
                                    'net' => $insights['net_balance'],
                                    'savings_rate' => $insights['savings_rate'],
                                    'burn_rate' => $insights['burn_rate'],
                                ],
                                'top_categories' => array_slice($insights['category_breakdown'], 0, 3, true),
                                'anomalies' => $insights['anomalies'],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray(false);
            $content = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

            return $content !== '' ? $content : null;
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
     *   ai_summary: string
     * } $insights
     */
    private function generateLocalSummary(array $insights): string
    {
        $topCategory = array_key_first($insights['category_breakdown']) ?? 'N/A';
        $hasAnomalies = count($insights['anomalies']) > 0 ? 'Yes' : 'No';

        return sprintf(
            'Income: %.2f TND, Expenses: %.2f TND, Net: %.2f TND. Savings rate is %.2f%%, burn rate is %.2f%%. Top expense category: %s. High-expense anomalies detected: %s.',
            $insights['total_income'],
            $insights['total_expenses'],
            $insights['net_balance'],
            $insights['savings_rate'],
            $insights['burn_rate'],
            $topCategory,
            $hasAnomalies
        );
    }

}
