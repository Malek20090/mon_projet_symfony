<?php

namespace App\Service;

use App\Entity\Expense;
use App\Entity\RecurringTransactionRule;
use App\Entity\Revenue;
use App\Entity\User;
use App\Repository\RecurringTransactionRuleRepository;

class RecurringPatternService
{
    public function __construct(
        private readonly RecurringTransactionRuleRepository $ruleRepository,
    ) {
    }

    /**
     * @param Revenue[] $revenues
     * @param Expense[] $expenses
     * @return array<int, array{
     *   kind: string,
     *   label: string,
     *   amount: float,
     *   frequency: string,
     *   next_run_at: string,
     *   confidence: float,
     *   occurrences: int,
     *   last_date: string,
     *   signature: string,
     *   revenue_type?: string,
     *   expense_category?: string,
     *   description?: string,
     *   expense_revenue_id?: int
     * }>
     */
    public function buildSuggestions(User $user, array $revenues, array $expenses): array
    {
        $suggestions = [];
        $seen = [];

        foreach ($this->detectRevenuePatterns($revenues) as $candidate) {
            if ($this->ruleRepository->existsForUserSignature($user, $candidate['signature'])) {
                continue;
            }
            $suggestions[] = $candidate;
            $seen[$candidate['signature']] = true;
        }

        foreach ($this->detectExpensePatterns($expenses) as $candidate) {
            if (isset($seen[$candidate['signature']])) {
                continue;
            }
            if ($this->ruleRepository->existsForUserSignature($user, $candidate['signature'])) {
                continue;
            }
            $suggestions[] = $candidate;
        }

        usort(
            $suggestions,
            static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']
        );

        return array_slice($suggestions, 0, 6);
    }

    /**
     * @param Revenue[] $revenues
     * @return array<int, array<string, mixed>>
     */
    private function detectRevenuePatterns(array $revenues): array
    {
        $groups = [];

        foreach ($revenues as $revenue) {
            $date = $revenue->getReceivedAt();
            if (!$date) {
                continue;
            }
            $amount = (float) $revenue->getAmount();
            if ($amount <= 0) {
                continue;
            }

            $amountBucket = number_format($amount, 2, '.', '');
            $type = strtoupper((string) $revenue->getType());
            $descNorm = $this->normalizeText((string) ($revenue->getDescription() ?? ''));
            $descKey = $descNorm !== '' ? $descNorm : 'no-desc';
            $key = implode('|', ['REV', $type, $amountBucket, $descKey]);
            $groups[$key][] = $revenue;
        }

        return $this->buildSuggestionsFromGroups($groups, RecurringTransactionRule::KIND_REVENUE);
    }

    /**
     * @param Expense[] $expenses
     * @return array<int, array<string, mixed>>
     */
    private function detectExpensePatterns(array $expenses): array
    {
        $groups = [];
        $monthlyCategoryGroups = [];

        foreach ($expenses as $expense) {
            $date = $expense->getExpenseDate();
            if (!$date) {
                continue;
            }
            $amount = (float) ($expense->getAmount() ?? 0);
            if ($amount <= 0) {
                continue;
            }

            // Relax amount matching so near-identical repeated charges are grouped together.
            $amountBucket = number_format(round($amount / 5) * 5, 2, '.', '');
            $category = strtoupper((string) ($expense->getCategory() ?? 'OTHER'));
            $descNorm = $this->normalizeText((string) ($expense->getDescription() ?? ''));
            // Keep only a short semantic prefix to avoid over-splitting by tiny description changes.
            $descWords = preg_split('/\s+/', $descNorm) ?: [];
            $descKey = $descNorm !== '' ? implode(' ', array_slice($descWords, 0, 3)) : 'no-desc';
            $key = implode('|', ['EXP', $category, $amountBucket, $descKey]);
            $groups[$key][] = $expense;

            $monthKey = $date->format('Y-m');
            $monthlyCategoryKey = implode('|', ['EXP_MONTH', $category, $monthKey]);
            $monthlyCategoryGroups[$monthlyCategoryKey][] = $expense;
        }

        foreach ($monthlyCategoryGroups as $groupKey => $items) {
            if (count($items) < 2) {
                continue;
            }
            $groups[$groupKey] = $items;
        }

        return $this->buildSuggestionsFromGroups($groups, RecurringTransactionRule::KIND_EXPENSE);
    }

    /**
     * @param array<string, array<int, Revenue|Expense>> $groups
     * @return array<int, array<string, mixed>>
     */
    private function buildSuggestionsFromGroups(array $groups, string $kind): array
    {
        $suggestions = [];

        foreach ($groups as $groupKey => $items) {
            $minOccurrences = $kind === RecurringTransactionRule::KIND_EXPENSE ? 2 : 3;
            if (count($items) < $minOccurrences) {
                continue;
            }

            usort($items, static function ($a, $b): int {
                $aDate = $a instanceof Revenue ? $a->getReceivedAt() : $a->getExpenseDate();
                $bDate = $b instanceof Revenue ? $b->getReceivedAt() : $b->getExpenseDate();
                if (!$aDate || !$bDate) {
                    return 0;
                }
                return $aDate <=> $bDate;
            });

            $intervals = [];
            for ($i = 1; $i < count($items); $i++) {
                $prevDate = $items[$i - 1] instanceof Revenue ? $items[$i - 1]->getReceivedAt() : $items[$i - 1]->getExpenseDate();
                $currDate = $items[$i] instanceof Revenue ? $items[$i]->getReceivedAt() : $items[$i]->getExpenseDate();
                if (!$prevDate || !$currDate) {
                    continue;
                }
                $intervals[] = (int) $prevDate->diff($currDate)->days;
            }
            if ($intervals === []) {
                continue;
            }

            $avgInterval = array_sum($intervals) / count($intervals);
            $frequency = $this->classifyFrequency($avgInterval);
            if (
                $frequency === null
                && $kind === RecurringTransactionRule::KIND_EXPENSE
                && $this->hasTwoOccurrencesInSameMonth($items)
            ) {
                $frequency = RecurringTransactionRule::FREQ_MONTHLY;
            }
            if ($frequency === null) {
                continue;
            }

            $amounts = array_map(
                static fn ($item): float => (float) ($item instanceof Revenue ? $item->getAmount() : ($item->getAmount() ?? 0.0)),
                $items
            );
            $meanAmount = array_sum($amounts) / max(count($amounts), 1);
            if ($meanAmount <= 0) {
                continue;
            }

            $variance = 0.0;
            foreach ($amounts as $amount) {
                $variance += (($amount - $meanAmount) ** 2);
            }
            $stdDev = sqrt($variance / max(count($amounts), 1));
            $relativeAmountStability = max(0.0, 1.0 - ($stdDev / max($meanAmount, 1.0)));

            $intervalStd = $this->stdDev($intervals);
            $intervalStability = max(0.0, 1.0 - ($intervalStd / max($avgInterval, 1.0)));

            $occurrenceBoost = min(1.0, count($items) / 6.0);
            $confidence = max(0.0, min(0.99, (0.45 * $intervalStability) + (0.4 * $relativeAmountStability) + (0.15 * $occurrenceBoost)));
            if ($confidence < 0.55) {
                continue;
            }

            $last = end($items);
            if (!$last) {
                continue;
            }
            $lastDate = $last instanceof Revenue ? $last->getReceivedAt() : $last->getExpenseDate();
            if (!$lastDate) {
                continue;
            }
            $nextRunAt = $this->computeNextRunDate($lastDate, $frequency);

            $label = $this->resolveLabel($kind, $last);
            $description = $last instanceof Revenue
                ? $last->getDescription()
                : $last->getDescription();

            $signature = hash('sha1', implode('|', [$kind, strtoupper($groupKey), strtoupper($frequency)]));

            $payload = [
                'kind' => $kind,
                'label' => $label,
                'amount' => round($meanAmount, 2),
                'frequency' => $frequency,
                'next_run_at' => $nextRunAt->format('Y-m-d'),
                'confidence' => round($confidence, 2),
                'occurrences' => count($items),
                'last_date' => $lastDate->format('Y-m-d'),
                'signature' => $signature,
                'description' => $description ? trim((string) $description) : null,
            ];

            if ($kind === RecurringTransactionRule::KIND_REVENUE && $last instanceof Revenue) {
                $payload['revenue_type'] = (string) $last->getType();
            }

            if ($kind === RecurringTransactionRule::KIND_EXPENSE && $last instanceof Expense) {
                $payload['expense_category'] = (string) ($last->getCategory() ?? 'Other');
                $payload['expense_revenue_id'] = $last->getRevenue()?->getId();
            }

            $suggestions[] = $payload;
        }

        return $suggestions;
    }

    /**
     * @param array<int, Revenue|Expense> $items
     */
    private function hasTwoOccurrencesInSameMonth(array $items): bool
    {
        $months = [];

        foreach ($items as $item) {
            if (!$item instanceof Expense) {
                continue;
            }

            $date = $item->getExpenseDate();
            if (!$date) {
                continue;
            }

            $monthKey = $date->format('Y-m');
            $months[$monthKey] = ($months[$monthKey] ?? 0) + 1;
            if ($months[$monthKey] >= 2) {
                return true;
            }
        }

        return false;
    }

    private function classifyFrequency(float $avgIntervalDays): ?string
    {
        if ($avgIntervalDays >= 6 && $avgIntervalDays <= 9) {
            return RecurringTransactionRule::FREQ_WEEKLY;
        }
        if ($avgIntervalDays >= 25 && $avgIntervalDays <= 35) {
            return RecurringTransactionRule::FREQ_MONTHLY;
        }

        return null;
    }

    private function computeNextRunDate(\DateTimeInterface $lastDate, string $frequency): \DateTimeImmutable
    {
        $base = \DateTimeImmutable::createFromInterface($lastDate);
        return match ($frequency) {
            RecurringTransactionRule::FREQ_WEEKLY => $base->modify('+7 days'),
            default => $base->modify('+1 month'),
        };
    }

    private function normalizeText(string $text): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', strtolower(trim($text))) ?? '';
        $normalized = trim($normalized);

        if (strlen($normalized) > 40) {
            $normalized = substr($normalized, 0, 40);
        }

        return $normalized;
    }

    /**
     * @param int[] $values
     */
    private function stdDev(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        $mean = array_sum($values) / count($values);
        $variance = 0.0;
        foreach ($values as $value) {
            $variance += (($value - $mean) ** 2);
        }

        return sqrt($variance / count($values));
    }

    private function resolveLabel(string $kind, Revenue|Expense $item): string
    {
        if ($kind === RecurringTransactionRule::KIND_REVENUE && $item instanceof Revenue) {
            $description = trim((string) ($item->getDescription() ?? ''));
            return $description !== '' ? $description : sprintf('Recurring revenue (%s)', $item->getType());
        }

        if ($item instanceof Expense) {
            $description = trim((string) ($item->getDescription() ?? ''));
            if ($description !== '') {
                return $description;
            }
            return sprintf('Recurring %s expense', (string) ($item->getCategory() ?? 'Other'));
        }

        return 'Recurring transaction';
    }
}
