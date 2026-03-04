<?php

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\UserBehaviorProfile;

class UserBehaviorScoringService
{
    /**
     * @param array<int, Transaction> $transactions
     * @return array{
     *   entity: UserBehaviorProfile,
     *   metrics: array<string, int|float>,
     *   week_tracking: array<string, int|float>,
     *   score_delta: int
     * }
     */
    public function buildSnapshot(
        User $user,
        array $transactions,
        ?UserBehaviorProfile $existingProfile = null
    ): array {
        $now = new \DateTimeImmutable('today');
        $from90 = $now->modify('-90 days');

        $last90 = array_values(array_filter(
            $transactions,
            static fn (Transaction $t): bool => $t->getDate() >= $from90
        ));

        $profile = $existingProfile ?? (new UserBehaviorProfile())->setUser($user);

        if ($last90 === []) {
            $profile
                ->setScore(50)
                ->setProfileType('Insufficient Data')
                ->setStrengths(['Tracking activated'])
                ->setWeaknesses(['Not enough transactions in the last 90 days'])
                ->setNextActions([
                    'Log each expense and saving transaction for 2 weeks.',
                    'Set one weekly spending cap and follow it.',
                    'Enable one automatic monthly saving transfer.'
                ])
                ->setUpdatedAt(new \DateTimeImmutable());

            return [
                'entity' => $profile,
                'metrics' => [
                    'regularity' => 50,
                    'impulsivity_control' => 50,
                    'saving_discipline' => 50,
                    'monthly_stability' => 50,
                ],
                'week_tracking' => [
                    'current_week_expense' => 0.0,
                    'previous_week_expense' => 0.0,
                    'expense_delta_pct' => 0,
                ],
                'score_delta' => $profile->getScore() - ($existingProfile?->getScore() ?? 50),
            ];
        }

        $regularity = $this->computeRegularityScore($last90);
        $impulsivityControl = $this->computeImpulsivityControlScore($last90);
        $savingDiscipline = $this->computeSavingDisciplineScore($last90);
        $monthlyStability = $this->computeMonthlyStabilityScore($last90);

        $score = (int) round(
            ($regularity * 0.28)
            + ($impulsivityControl * 0.22)
            + ($savingDiscipline * 0.30)
            + ($monthlyStability * 0.20)
        );

        $profileType = $this->resolveProfileType($score);
        $strengths = $this->resolveStrengths(
            $regularity,
            $impulsivityControl,
            $savingDiscipline,
            $monthlyStability
        );
        $weaknesses = $this->resolveWeaknesses(
            $regularity,
            $impulsivityControl,
            $savingDiscipline,
            $monthlyStability
        );
        $nextActions = $this->buildNextActions($weaknesses);

        $previousScore = $existingProfile?->getScore() ?? $score;
        $weekTracking = $this->buildWeeklyTracking($last90, $now);

        $profile
            ->setScore($score)
            ->setProfileType($profileType)
            ->setStrengths($strengths)
            ->setWeaknesses($weaknesses)
            ->setNextActions($nextActions)
            ->setUpdatedAt(new \DateTimeImmutable());

        return [
            'entity' => $profile,
            'metrics' => [
                'regularity' => $regularity,
                'impulsivity_control' => $impulsivityControl,
                'saving_discipline' => $savingDiscipline,
                'monthly_stability' => $monthlyStability,
            ],
            'week_tracking' => $weekTracking,
            'score_delta' => $score - $previousScore,
        ];
    }

    /**
     * @param array<int, Transaction> $transactions
     */
    private function computeRegularityScore(array $transactions): int
    {
        $weeklyExpenseTotals = [];

        foreach ($transactions as $transaction) {
            if ($transaction->getType() !== 'EXPENSE') {
                continue;
            }
            $key = $transaction->getDate()->format('o-W');
            if (!isset($weeklyExpenseTotals[$key])) {
                $weeklyExpenseTotals[$key] = 0.0;
            }
            $weeklyExpenseTotals[$key] += (float) $transaction->getMontant();
        }

        if (count($weeklyExpenseTotals) < 2) {
            return 65;
        }

        $values = array_values($weeklyExpenseTotals);
        $mean = array_sum($values) / count($values);
        if ($mean <= 0.0) {
            return 80;
        }

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= count($values);
        $std = sqrt($variance);
        $cv = $std / $mean;

        return max(0, min(100, (int) round(100 - ($cv * 120))));
    }

    /**
     * @param array<int, Transaction> $transactions
     */
    private function computeImpulsivityControlScore(array $transactions): int
    {
        $expenses = array_values(array_map(
            static fn (Transaction $t): float => (float) $t->getMontant(),
            array_filter($transactions, static fn (Transaction $t): bool => $t->getType() === 'EXPENSE')
        ));

        if ($expenses === []) {
            return 85;
        }

        $mean = array_sum($expenses) / count($expenses);
        $variance = 0.0;
        foreach ($expenses as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= count($expenses);
        $std = sqrt($variance);

        $threshold = max($mean * 1.8, $mean + (1.5 * $std));
        $spikes = count(array_filter($expenses, static fn (float $value): bool => $value > $threshold));
        $spikeRatio = $spikes / max(1, count($expenses));

        return max(0, min(100, (int) round(100 - ($spikeRatio * 220))));
    }

    /**
     * @param array<int, Transaction> $transactions
     */
    private function computeSavingDisciplineScore(array $transactions): int
    {
        $savingTotal = 0.0;
        $expenseTotal = 0.0;
        $savingCount = 0;

        foreach ($transactions as $transaction) {
            $amount = (float) $transaction->getMontant();
            if ($transaction->getType() === 'SAVING') {
                $savingTotal += $amount;
                ++$savingCount;
            } elseif ($transaction->getType() === 'EXPENSE') {
                $expenseTotal += $amount;
            }
        }

        $denominator = $savingTotal + $expenseTotal;
        $savingRate = $denominator > 0 ? ($savingTotal / $denominator) : 0.0;
        $savingFrequency = $savingCount / max(1, count($transactions));

        $score = (($savingRate * 0.7) + ($savingFrequency * 0.3)) * 100;

        return max(0, min(100, (int) round($score)));
    }

    /**
     * @param array<int, Transaction> $transactions
     */
    private function computeMonthlyStabilityScore(array $transactions): int
    {
        $monthlyNet = [];

        foreach ($transactions as $transaction) {
            $key = $transaction->getDate()->format('Y-m');
            if (!isset($monthlyNet[$key])) {
                $monthlyNet[$key] = 0.0;
            }
            if ($transaction->getType() === 'SAVING') {
                $monthlyNet[$key] += (float) $transaction->getMontant();
            } elseif ($transaction->getType() === 'EXPENSE') {
                $monthlyNet[$key] -= (float) $transaction->getMontant();
            }
        }

        if (count($monthlyNet) < 2) {
            return 65;
        }

        $values = array_values($monthlyNet);
        $mean = array_sum($values) / count($values);
        $baseline = max(1.0, abs($mean));

        $variance = 0.0;
        foreach ($values as $value) {
            $variance += ($value - $mean) ** 2;
        }
        $variance /= count($values);
        $std = sqrt($variance);
        $cv = $std / $baseline;

        return max(0, min(100, (int) round(100 - ($cv * 100))));
    }

    private function resolveProfileType(int $score): string
    {
        if ($score >= 80) {
            return 'Prudent';
        }
        if ($score >= 65) {
            return 'Variable but Controlled';
        }
        if ($score >= 50) {
            return 'Reactive Spending';
        }

        return 'High Financial Risk';
    }

    /**
     * @return array<int, string>
     */
    private function resolveStrengths(
        int $regularity,
        int $impulsivityControl,
        int $savingDiscipline,
        int $monthlyStability
    ): array {
        $map = [
            'Expense regularity' => $regularity,
            'Impulsivity control' => $impulsivityControl,
            'Saving discipline' => $savingDiscipline,
            'Monthly stability' => $monthlyStability,
        ];
        arsort($map);

        $strengths = [];
        foreach ($map as $label => $value) {
            if ($value >= 70) {
                $strengths[] = sprintf('%s (%d/100)', $label, $value);
            }
            if (count($strengths) === 3) {
                break;
            }
        }

        return $strengths !== [] ? $strengths : ['No dominant strength yet'];
    }

    /**
     * @return array<int, string>
     */
    private function resolveWeaknesses(
        int $regularity,
        int $impulsivityControl,
        int $savingDiscipline,
        int $monthlyStability
    ): array {
        $map = [
            'Expense regularity' => $regularity,
            'Impulsivity control' => $impulsivityControl,
            'Saving discipline' => $savingDiscipline,
            'Monthly stability' => $monthlyStability,
        ];
        asort($map);

        $weaknesses = [];
        foreach ($map as $label => $value) {
            if ($value <= 55) {
                $weaknesses[] = sprintf('%s (%d/100)', $label, $value);
            }
            if (count($weaknesses) === 3) {
                break;
            }
        }

        return $weaknesses !== [] ? $weaknesses : ['No critical weakness detected'];
    }

    /**
     * @param array<int, string> $weaknesses
     * @return array<int, string>
     */
    private function buildNextActions(array $weaknesses): array
    {
        $actions = [];

        foreach ($weaknesses as $weakness) {
            if (str_contains($weakness, 'Impulsivity control')) {
                $actions[] = 'Apply a 24-hour delay rule for non-essential purchases above 100 TND.';
            }
            if (str_contains($weakness, 'Saving discipline')) {
                $actions[] = 'Set an automatic transfer of 10% of each income to savings.';
            }
            if (str_contains($weakness, 'Expense regularity')) {
                $actions[] = 'Use a fixed weekly spending cap and track it every Sunday.';
            }
            if (str_contains($weakness, 'Monthly stability')) {
                $actions[] = 'Reduce variable expenses by 15% over the next 4 weeks.';
            }
        }

        $actions = array_values(array_unique($actions));

        if (count($actions) < 3) {
            $actions[] = 'Review your 3 biggest expenses every Monday and cut one recurring cost.';
        }
        if (count($actions) < 3) {
            $actions[] = 'Define one weekly micro-goal and mark it as completed in your dashboard.';
        }
        if (count($actions) < 3) {
            $actions[] = 'Set a monthly alert when expenses exceed 80% of your planned budget.';
        }

        return array_slice($actions, 0, 3);
    }

    /**
     * @param array<int, Transaction> $transactions
     * @return array<string, int|float>
     */
    private function buildWeeklyTracking(array $transactions, \DateTimeImmutable $now): array
    {
        $startCurrent = $now->modify('-6 days')->setTime(0, 0);
        $startPrevious = $startCurrent->modify('-7 days');
        $endPrevious = $startCurrent->modify('-1 second');

        $currentWeekExpense = 0.0;
        $previousWeekExpense = 0.0;

        foreach ($transactions as $transaction) {
            if ($transaction->getType() !== 'EXPENSE') {
                continue;
            }
            $date = \DateTimeImmutable::createFromMutable($transaction->getDate());
            $amount = (float) $transaction->getMontant();

            if ($date >= $startCurrent) {
                $currentWeekExpense += $amount;
            } elseif ($date >= $startPrevious && $date <= $endPrevious) {
                $previousWeekExpense += $amount;
            }
        }

        $deltaPct = 0;
        if ($previousWeekExpense > 0) {
            $deltaPct = (int) round((($currentWeekExpense - $previousWeekExpense) / $previousWeekExpense) * 100);
        } elseif ($currentWeekExpense > 0) {
            $deltaPct = 100;
        }

        return [
            'current_week_expense' => round($currentWeekExpense, 2),
            'previous_week_expense' => round($previousWeekExpense, 2),
            'expense_delta_pct' => $deltaPct,
        ];
    }
}
