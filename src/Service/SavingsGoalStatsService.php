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
}
