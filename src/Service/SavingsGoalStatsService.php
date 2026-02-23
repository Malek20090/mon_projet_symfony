<?php

namespace App\Service;

use App\Entity\SavingAccount;
use App\Entity\FinancialGoal;

class SavingsGoalStatsService
{
    /**
     * @param FinancialGoal[] $goals
     */
    public function buildStats(SavingAccount $account, array $goals): array
    {
        $balance = (float)($account->getSold() ?? 0);

        $activeGoals = count($goals);

        $avgProgress = 0;
        $nearestDeadline = null;

        $sum = 0;
        foreach ($goals as $g) {
            $target = (float)$g->getMontantCible();
            $current = (float)$g->getMontantActuel();
            $pct = ($target > 0) ? min(100, max(0, ($current / $target) * 100)) : 0;
            $sum += $pct;

            if ($g->getDateLimite()) {
                if ($nearestDeadline === null || $g->getDateLimite() < $nearestDeadline) {
                    $nearestDeadline = $g->getDateLimite();
                }
            }
        }
        if ($activeGoals > 0) {
            $avgProgress = (int)round($sum / $activeGoals);
        }

        $alerts = $this->buildAlerts($balance, $goals);
        $insights = $this->buildInsights($account, $goals);
        $badges = $this->buildBadges($balance, $avgProgress, $activeGoals);

        return [
            'balance' => $balance,
            'activeGoals' => $activeGoals,
            'avgProgress' => $avgProgress,
            'nearestDeadline' => $nearestDeadline?->format('Y-m-d') ?? '--/--/----',
            'alerts' => $alerts,
            'insights' => $insights,
            'badges' => $badges,
        ];
    }

    /**
     * Minimal functional alerts (NOW)
     */
    private function buildAlerts(float $balance, array $goals): array
    {
        $items = [];

        // rule: low buffer
        if ($balance < 200) {
            $items[] = ['level' => 'danger', 'title' => 'Low savings buffer', 'desc' => 'Your balance is low. Consider building an emergency fund first.'];
        }

        // rule: near deadlines / high priority / low progress
        $now = new \DateTime('today');
        foreach ($goals as $g) {
            $target = (float)$g->getMontantCible();
            $current = (float)$g->getMontantActuel();
            $pct = ($target > 0) ? (($current / $target) * 100) : 0;

            if ($g->getDateLimite()) {
                $diffDays = (int)$now->diff($g->getDateLimite())->format('%r%a');
                if ($diffDays >= 0 && $diffDays <= 14) {
                    $items[] = ['level' => 'warn', 'title' => 'Upcoming deadline', 'desc' => 'Goal "'.$g->getNom().'" is due in '.$diffDays.' days.'];
                }
            }
            if (($g->getPriorite() ?? 0) >= 4 && $pct < 30) {
                $items[] = ['level' => 'warn', 'title' => 'High priority needs action', 'desc' => 'Goal "'.$g->getNom().'" is high priority but progress is still low.'];
            }
        }

        if (count($items) === 0) {
            $items[] = ['level' => 'ok', 'title' => 'All good', 'desc' => 'No urgent alerts right now. Keep saving consistently.'];
        }

        return array_slice($items, 0, 5);
    }

    /**
     * Minimal functional insights (NOW)
     */
    private function buildInsights(SavingAccount $account, array $goals): array
    {
        $items = [];

        if ((float)$account->getTauxInteret() <= 0) {
            $items[] = ['title' => 'Interest Tip', 'desc' => 'Your interest rate is 0%. Consider setting a small rate for long-term growth.'];
        }

        if (count($goals) === 0) {
            $items[] = ['title' => 'Goal Tip', 'desc' => 'Create at least 1 goal to track progress and stay motivated.'];
        }

        if (count($items) === 0) {
            $items[] = ['title' => 'Nice!', 'desc' => 'Your setup looks good. Keep contributing to your nearest deadline goal.'];
        }

        return $items;
    }

    /**
     * Minimal functional gamification (NOW)
     */
    private function buildBadges(float $balance, int $avgProgress, int $activeGoals): array
    {
        $badges = [];

        if ($activeGoals >= 1) $badges[] = '🎯 Goal Creator';
        if ($balance >= 1000) $badges[] = '💎 1K Saved';
        if ($avgProgress >= 50) $badges[] = '📈 Halfway There';
        if (count($badges) === 0) $badges[] = '🐣 Starter';

        return $badges;
    }

    /**
     * @param array<int, array<string, mixed>> $goalRows
     *
     * @return array{
     *   summary: array<string, float|int|string>,
     *   topUrgent: array<int, array<string, mixed>>,
     *   alerts: array<int, array{level: string, message: string}>,
     *   recommendations: array<int, string>
     * }
     */
    public function buildGoalHealthDashboard(array $goalRows, float $availableBalance = 0.0): array
    {
        $normalized = [];
        $totalDailyNeeded = 0.0;
        $totalMonthlyNeeded = 0.0;
        $alerts = [];

        foreach ($goalRows as $row) {
            $target = (float) ($row['montantCible'] ?? 0);
            $current = (float) ($row['montantActuel'] ?? 0);
            $progressRatio = (float) ($row['progressRatio'] ?? 0);
            $progressRatio = min(1.0, max(0.0, $progressRatio));
            $progressPct = $progressRatio * 100;
            $dailyNeeded = max(0.0, (float) ($row['dailyNeeded'] ?? 0));
            $monthlyNeeded = max(0.0, (float) ($row['monthlyNeeded'] ?? ($dailyNeeded * 30.0)));
            $daysLeftRaw = (int) ($row['daysLeft'] ?? 999999);
            $daysLeft = $daysLeftRaw >= 999999 ? null : $daysLeftRaw;
            $urgencyScore = max(0.0, (float) ($row['urgencyScore'] ?? 0));
            $remaining = max(0.0, (float) ($row['remainingAmount'] ?? ($target - $current)));
            $priority = (int) ($row['priorite'] ?? 3);

            if ($remaining <= 0.00001) {
                $status = 'completed';
            } elseif ($daysLeft !== null && $daysLeft < 0) {
                $status = 'overdue';
            } elseif ($urgencyScore >= 85 || ($daysLeft !== null && $daysLeft <= 7) || $progressPct < 25) {
                $status = 'critical';
            } elseif ($urgencyScore >= 60 || ($daysLeft !== null && $daysLeft <= 21) || $progressPct < 55) {
                $status = 'warning';
            } else {
                $status = 'on_track';
            }

            if ($remaining > 0.00001) {
                $totalDailyNeeded += $dailyNeeded;
                $totalMonthlyNeeded += $monthlyNeeded;
            }

            $pressureIndex = $availableBalance > 0 ? $monthlyNeeded / $availableBalance : ($monthlyNeeded > 0 ? 999.0 : 0.0);

            if ($status === 'overdue') {
                $alerts[] = ['level' => 'danger', 'message' => sprintf('Goal "%s" is overdue. Increase contribution now.', (string) ($row['nom'] ?? 'Goal'))];
            } elseif ($daysLeft !== null && $daysLeft <= 7 && $remaining > 0) {
                $alerts[] = ['level' => 'warn', 'message' => sprintf('Goal "%s" is due in %d days.', (string) ($row['nom'] ?? 'Goal'), $daysLeft)];
            } elseif ($priority >= 4 && $progressPct < 40) {
                $alerts[] = ['level' => 'warn', 'message' => sprintf('High-priority goal "%s" has low progress.', (string) ($row['nom'] ?? 'Goal'))];
            }

            $normalized[] = [
                'id' => (int) ($row['id'] ?? 0),
                'nom' => (string) ($row['nom'] ?? 'Goal'),
                'priority' => $priority,
                'target' => $target,
                'current' => $current,
                'remaining' => $remaining,
                'progressPct' => $progressPct,
                'daysLeft' => $daysLeft,
                'dailyNeeded' => $dailyNeeded,
                'monthlyNeeded' => $monthlyNeeded,
                'pressureIndex' => $pressureIndex,
                'urgencyScore' => $urgencyScore,
                'status' => $status,
            ];
        }

        usort($normalized, static fn(array $a, array $b): int => $b['urgencyScore'] <=> $a['urgencyScore']);

        $monthlyNeeded = $totalMonthlyNeeded > 0 ? $totalMonthlyNeeded : ($totalDailyNeeded * 30.0);
        $coverageRaw = $monthlyNeeded > 0 ? ($availableBalance / $monthlyNeeded) * 100.0 : 100.0;
        $coveragePct = min(100.0, $coverageRaw);
        $goalPressureIndex = $availableBalance > 0 ? $monthlyNeeded / $availableBalance : ($monthlyNeeded > 0 ? 999.0 : 0.0);
        $feasibilityStatus = 'on_track';
        if ($monthlyNeeded <= 0.00001) {
            $feasibilityStatus = 'stable';
        } elseif ($coverageRaw < 90) {
            $feasibilityStatus = 'at_risk';
        } elseif ($coverageRaw < 120) {
            $feasibilityStatus = 'tight';
        }

        $recommendedMonthly = $monthlyNeeded > 0 ? round($monthlyNeeded * 1.1, 2) : 0.0;
        $recommendedWeekly = $recommendedMonthly > 0 ? round($recommendedMonthly / 4.0, 2) : 0.0;
        $recommendations = [];
        if ($monthlyNeeded > 0.0) {
            $recommendations[] = sprintf('Contribute at least %.2f TND/month (%.2f TND/week) to stay on track.', $recommendedMonthly, $recommendedWeekly);
        }
        if ($feasibilityStatus === 'at_risk') {
            $recommendations[] = 'Reorder priorities and focus contributions on top urgent goals first.';
        }
        if (count($recommendations) === 0) {
            $recommendations[] = 'Your current goals are stable. Keep consistent contributions.';
        }
        if (count($alerts) === 0) {
            $alerts[] = ['level' => 'ok', 'message' => 'No urgent issue detected for your current goals.'];
        }

        return [
            'summary' => [
                'totalDailyNeeded' => $totalDailyNeeded,
                'monthlyNeeded' => $monthlyNeeded,
                'coveragePct' => $coveragePct,
                'coverageRawPct' => $coverageRaw,
                'feasibilityStatus' => $feasibilityStatus,
                'goalPressureIndex' => $goalPressureIndex,
                'recommendedMonthlyContribution' => $recommendedMonthly,
                'recommendedWeeklyContribution' => $recommendedWeekly,
                'criticalCount' => count(array_filter($normalized, static fn(array $r): bool => $r['status'] === 'critical')),
                'warningCount' => count(array_filter($normalized, static fn(array $r): bool => $r['status'] === 'warning')),
                'overdueCount' => count(array_filter($normalized, static fn(array $r): bool => $r['status'] === 'overdue')),
            ],
            'topUrgent' => array_slice($normalized, 0, 3),
            'alerts' => array_slice($alerts, 0, 5),
            'recommendations' => $recommendations,
        ];
    }
}
