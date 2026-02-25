<?php

namespace App\Service;

final class GoalWhatIfAdvisor
{
    private const MAX_REASONABLE_MONTHLY_INCREASE = 250.0;
    private const MAX_REASONABLE_DEADLINE_EXTENSION = 3;
    private const MAX_REASONABLE_ONE_TIME_RATIO = 0.35;

    public function __construct(private readonly GoalWhatIfService $goalWhatIfService)
    {
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $context
     * @return array{
     *   executive_insight:string,
     *   why:string,
     *   best_action:array{title:string,details:string},
     *   alternatives:array<int,array{title:string,details:string}>,
     *   next_7_days:string,
     *   options:array<int,array{title:string,details:string}>
     * }
     */
    public function build(array $metrics, array $context): array
    {
        $goalName = (string) ($context['goal_name'] ?? 'Goal');
        $todayDate = (string) ($context['today_date'] ?? date('Y-m-d'));
        $currentSaved = (float) ($context['current_saved'] ?? 0.0);
        $targetAmount = (float) ($context['target_amount'] ?? 0.0);
        $deadlineDate = isset($context['deadline_date']) && is_string($context['deadline_date']) ? $context['deadline_date'] : null;
        $monthly = max(0.0, (float) ($context['monthly_deposit'] ?? 0.0));
        $oneTime = max(0.0, (float) ($context['one_time_deposit'] ?? 0.0));

        $remaining = max(0.0, (float) ($metrics['remaining_after_now'] ?? 0.0));
        $risk = strtoupper((string) ($metrics['risk_level'] ?? 'HIGH'));
        $gap = max(0, (int) ($metrics['deadline_gap_months'] ?? 0));
        $confidence = (float) ($metrics['deadline_confidence'] ?? 0.0);
        $requiredMonthlyRaw = max(0.0, (float) ($metrics['required_monthly_to_hit_deadline'] ?? 0.0));
        $requiredMonthly = $this->roundMoneyForAdvice($requiredMonthlyRaw);
        $projectedFinish = $this->projectedText($metrics['projected_finish_date'] ?? null);
        $feasibility = (float) ($metrics['feasibility_score'] ?? 0.0);
        $deltaMonths = isset($metrics['delta_months']) && is_numeric($metrics['delta_months']) ? (int) $metrics['delta_months'] : null;

        if ($remaining <= 0.0) {
            $exec = sprintf(
                'Goal "%s" is already achieved. Projected finish is %s with LOW risk and 100%% confidence.',
                $goalName,
                $todayDate
            );
            $why = sprintf(
                'Remaining after one-time deposit is %.2f TND and deadline gap is 0 month(s).',
                $remaining
            );
            $best = [
                'title' => 'Stabilize completed goal',
                'details' => $monthly > 0
                    ? sprintf('Set monthly deposit to 0 TND for this goal and reallocate the current %.2f TND/month to the next priority goal.', $monthly)
                    : 'Keep monthly deposit at 0 TND and lock this goal as completed.',
            ];
            $alternatives = [
                [
                    'title' => 'Increase target',
                    'details' => sprintf('Increase target by %.2f TND to keep saving momentum while preserving 100%% confidence.', $this->roundMoneyForAdvice(max(50.0, $targetAmount * 0.1))),
                ],
                [
                    'title' => 'Create next goal',
                    'details' => sprintf('Create a new goal and start with %.2f TND/month from next cycle.', $this->roundMoneyForAdvice(max(50.0, $monthly))),
                ],
            ];

            return [
                'executive_insight' => $exec,
                'why' => $why,
                'best_action' => $best,
                'alternatives' => $alternatives,
                'next_7_days' => sprintf('Archive "%s" and schedule your next goal transfer on day 1.', $goalName),
                'options' => array_merge([$best], $alternatives),
            ];
        }

        if ($monthly <= 0.0) {
            $restoreMonthly = $requiredMonthly > 0.0
                ? $requiredMonthly
                : $this->roundMoneyForAdvice(max(50.0, $remaining / 12.0));
            $simRequired = $this->goalWhatIfService->simulate([
                'current_saved' => $currentSaved,
                'target_amount' => $targetAmount,
                'deadline_date' => $deadlineDate,
                'today_date' => $todayDate,
                'monthly_deposit' => $restoreMonthly,
                'one_time_deposit' => $oneTime,
            ]);
            $newGap = (int) ($simRequired['deadline_gap_months'] ?? $gap);
            $newConf = (float) ($simRequired['deadline_confidence'] ?? $confidence);
            $exec = sprintf(
                'With 0 TND/month, "%s" has no projected finish date and remains at %s risk.',
                $goalName,
                $risk
            );
            $why = $requiredMonthly > 0.0
                ? sprintf(
                    'Required monthly is %.2f TND, current monthly is 0.00 TND, and deadline gap is %d month(s).',
                    $requiredMonthly,
                    $gap
                )
                : sprintf(
                    'No monthly contribution means no projected finish date; a %.2f TND/month baseline restores progress from %.2f TND remaining.',
                    $restoreMonthly,
                    $remaining
                );
            $best = [
                'title' => 'Restore feasibility',
                'details' => sprintf(
                    'Set monthly deposit to %.2f TND; %s',
                    $restoreMonthly,
                    $this->gapOutcomeText($gap, $newGap, $confidence, $newConf)
                ),
            ];
            $monthsToDeadline = max(1, (int) ($metrics['months_to_deadline'] ?? 1));
            $oneTimeNeed = $this->roundMoneyForAdvice(max(0.0, $remaining - ($restoreMonthly * $monthsToDeadline)));
            $alternatives = [
                [
                    'title' => 'One-time support',
                    'details' => sprintf('Add %.2f TND now and keep monthly at %.2f TND to reduce immediate pressure.', $oneTimeNeed, $restoreMonthly),
                ],
                [
                    'title' => 'Deadline relief',
                    'details' => sprintf('Extend deadline by %d month(s) to lower required monthly below %.2f TND.', max(1, $gap), $restoreMonthly),
                ],
            ];

            return [
                'executive_insight' => $exec,
                'why' => $why,
                'best_action' => $best,
                'alternatives' => $alternatives,
                'next_7_days' => sprintf('Set an automatic transfer of %.2f TND on day 1 of each month.', $restoreMonthly),
                'options' => array_merge([$best], $alternatives),
            ];
        }
        $scenarioType = (string) ($context['scenario_type'] ?? 'deposit_adjustment');
        $plan = $this->chooseBestPlan($metrics, [
            'scenario_type' => $scenarioType,
            'current_saved' => $currentSaved,
            'target_amount' => $targetAmount,
            'deadline_date' => $deadlineDate,
            'today_date' => $todayDate,
            'current_monthly' => $monthly,
            'one_time_deposit' => $oneTime,
        ]);

        $exec = $risk === 'LOW'
            ? sprintf(
                'Goal "%s" is safe: projected finish %s, LOW risk, confidence %.0f%%.',
                $goalName,
                $projectedFinish,
                $confidence
            )
            : sprintf(
                'Goal "%s" is %s: projected finish %s with confidence %.0f%%.',
                $goalName,
                $risk === 'HIGH' ? 'off-track' : 'close but not fully safe',
                $projectedFinish,
                $confidence
            );
        $why = sprintf(
            'Deadline gap is %d month(s), required monthly is %.2f TND, current monthly is %.2f TND, and timeline delta is %s.',
            $gap,
            $requiredMonthly,
            $monthly,
            $deltaMonths === null ? 'n/a' : sprintf('%+d month(s)', $deltaMonths)
        );

        return [
            'executive_insight' => $exec,
            'why' => $why,
            'best_action' => $plan['best_action'],
            'alternatives' => $plan['alternatives'],
            'next_7_days' => $plan['next_7_days'],
            'options' => array_merge([$plan['best_action']], $plan['alternatives']),
        ];
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $context
     * @return array{
     *   best_action:array{title:string,details:string},
     *   alternatives:array<int,array{title:string,details:string}>,
     *   next_7_days:string
     * }
     */
    private function chooseBestPlan(array $metrics, array $context): array
    {
        $risk = strtoupper((string) ($metrics['risk_level'] ?? 'HIGH'));
        $gap = max(0, (int) ($metrics['deadline_gap_months'] ?? 0));
        $confidence = (float) ($metrics['deadline_confidence'] ?? 0.0);
        $remaining = max(0.0, (float) ($metrics['remaining_after_now'] ?? 0.0));
        $monthsToDeadline = max(1, (int) ($metrics['months_to_deadline'] ?? 1));
        $requiredMonthlyRaw = max(0.0, (float) ($metrics['required_monthly_to_hit_deadline'] ?? 0.0));
        $currentMonthly = max(0.0, (float) ($context['current_monthly'] ?? 0.0));
        $baseOneTime = max(0.0, (float) ($context['one_time_deposit'] ?? 0.0));
        $scenarioType = (string) ($context['scenario_type'] ?? 'deposit_adjustment');
        $deadlineDate = isset($context['deadline_date']) && is_string($context['deadline_date']) ? $context['deadline_date'] : null;
        $currentSaved = max(0.0, (float) ($context['current_saved'] ?? 0.0));
        $targetAmount = max(0.0, (float) ($context['target_amount'] ?? 0.0));
        $todayDate = (string) ($context['today_date'] ?? date('Y-m-d'));

        $requiredAdjustment = max(0.0, $requiredMonthlyRaw - $currentMonthly);
        $maxReasonableIncrease = $this->roundMoneyForAdvice(max(
            self::MAX_REASONABLE_MONTHLY_INCREASE,
            $currentMonthly * 0.30
        ));
        $maxReasonableOneTime = $this->roundMoneyForAdvice($remaining * self::MAX_REASONABLE_ONE_TIME_RATIO);

        if ($risk === 'LOW' || $gap <= 0) {
            $optimizedMonthly = $this->roundMoneyForAdvice(max(0.0, $requiredMonthlyRaw));
            $reduction = $this->roundMoneyForAdvice(max(0.0, $currentMonthly - $optimizedMonthly));
            $simOptimized = $this->goalWhatIfService->simulate([
                'current_saved' => $currentSaved,
                'target_amount' => $targetAmount,
                'deadline_date' => $deadlineDate,
                'today_date' => $todayDate,
                'monthly_deposit' => $optimizedMonthly,
                'one_time_deposit' => $baseOneTime,
            ]);
            $finish = $this->projectedText($simOptimized['projected_finish_date'] ?? null);
            $conf = (float) ($simOptimized['deadline_confidence'] ?? $confidence);

            $best = $reduction > 0.0
                ? [
                    'title' => 'Optimize effort',
                    'details' => sprintf(
                        'Reduce monthly by %.2f TND to %.2f TND/month; projected finish stays %s with %.0f%% confidence.',
                        $reduction,
                        $optimizedMonthly,
                        $finish,
                        $conf
                    ),
                ]
                : [
                    'title' => 'Keep current plan',
                    'details' => sprintf(
                        'Keep %.2f TND/month; projected finish remains %s with %.0f%% confidence.',
                        $currentMonthly,
                        $this->projectedText($metrics['projected_finish_date'] ?? null),
                        $confidence
                    ),
                ];

            $alts = [
                [
                    'title' => 'Accelerate completion',
                    'details' => sprintf(
                        'Increase monthly to %.2f TND/month to finish earlier while keeping LOW risk.',
                        $this->roundMoneyForAdvice($currentMonthly + max(20.0, $currentMonthly * 0.2))
                    ),
                ],
                [
                    'title' => 'Minimum safe pace',
                    'details' => sprintf(
                        'Set monthly at %.2f TND/month to preserve deadline safety.',
                        $optimizedMonthly
                    ),
                ],
            ];

            return [
                'best_action' => $best,
                'alternatives' => $alts,
                'next_7_days' => sprintf(
                    'Set an automatic transfer to %.2f TND on day 1 of each month.',
                    $best['title'] === 'Optimize effort' ? $optimizedMonthly : $currentMonthly
                ),
            ];
        }

        $candidates = [];

        $newMonthly = $this->roundMoneyForAdvice(max($currentMonthly, $requiredMonthlyRaw));
        $monthlyIncrease = $this->roundMoneyForAdvice(max(0.0, $newMonthly - $currentMonthly));
        $simMonthly = $this->goalWhatIfService->simulate([
            'current_saved' => $currentSaved,
            'target_amount' => $targetAmount,
            'deadline_date' => $deadlineDate,
            'today_date' => $todayDate,
            'monthly_deposit' => $newMonthly,
            'one_time_deposit' => $baseOneTime,
        ]);
        $monthlyGap = (int) ($simMonthly['deadline_gap_months'] ?? $gap);
        $monthlyConf = (float) ($simMonthly['deadline_confidence'] ?? $confidence);
        $candidates['monthly'] = [
            'key' => 'monthly',
            'title' => 'Increase monthly deposit',
            'details' => sprintf(
                'Increase monthly by +%.2f TND to %.2f TND/month; %s',
                $monthlyIncrease,
                $newMonthly,
                $this->gapOutcomeText($gap, $monthlyGap, $confidence, $monthlyConf)
            ),
            'next_7_days' => sprintf('Set an automatic transfer to %.2f TND on day 1 of each month.', $newMonthly),
            'practicality' => $monthlyIncrease,
        ];

        $oneTimeNeededForGapOne = $this->roundMoneyForAdvice(max(
            0.0,
            $remaining - ($currentMonthly * ($monthsToDeadline + 1))
        ));
        $simOneTime = $this->goalWhatIfService->simulate([
            'current_saved' => $currentSaved,
            'target_amount' => $targetAmount,
            'deadline_date' => $deadlineDate,
            'today_date' => $todayDate,
            'monthly_deposit' => $currentMonthly,
            'one_time_deposit' => $baseOneTime + $oneTimeNeededForGapOne,
        ]);
        $oneTimeGap = (int) ($simOneTime['deadline_gap_months'] ?? $gap);
        $oneTimeConf = (float) ($simOneTime['deadline_confidence'] ?? $confidence);
        $oneTimeReasonable = $oneTimeNeededForGapOne <= $maxReasonableOneTime && $oneTimeGap <= 1;
        $candidates['one_time'] = [
            'key' => 'one_time',
            'title' => 'Add one-time deposit',
            'details' => sprintf(
                'Add %.2f TND this week and keep monthly at %.2f TND; %s',
                $oneTimeNeededForGapOne,
                $currentMonthly,
                $this->gapOutcomeText($gap, $oneTimeGap, $confidence, $oneTimeConf)
            ),
            'next_7_days' => sprintf(
                'Make a one-time deposit of %.2f TND this week and keep monthly transfer at %.2f TND.',
                $oneTimeNeededForGapOne,
                $currentMonthly
            ),
            'practicality' => $oneTimeNeededForGapOne + 50.0,
            'reasonable' => $oneTimeReasonable,
        ];

        if (is_string($deadlineDate) && $deadlineDate !== '') {
            $extendBy = max(1, $gap);
            $extendedDeadline = null;
            try {
                $extendedDeadline = (new \DateTimeImmutable($deadlineDate))
                    ->modify('+' . $extendBy . ' month')
                    ->format('Y-m-d');
            } catch (\Throwable $e) {
                $extendedDeadline = null;
            }
            if ($extendedDeadline !== null) {
                $simDeadline = $this->goalWhatIfService->simulate([
                    'current_saved' => $currentSaved,
                    'target_amount' => $targetAmount,
                    'deadline_date' => $extendedDeadline,
                    'today_date' => $todayDate,
                    'monthly_deposit' => $currentMonthly,
                    'one_time_deposit' => $baseOneTime,
                ]);
                $deadlineGap = (int) ($simDeadline['deadline_gap_months'] ?? $gap);
                $deadlineConf = (float) ($simDeadline['deadline_confidence'] ?? $confidence);
                $candidates['deadline'] = [
                    'key' => 'deadline',
                    'title' => 'Extend deadline',
                    'details' => sprintf(
                        'Extend deadline by %d month(s); %s',
                        $extendBy,
                        $this->gapOutcomeText($gap, $deadlineGap, $confidence, $deadlineConf)
                    ),
                    'next_7_days' => sprintf('Update this goal deadline by %d month(s) in goal settings this week.', $extendBy),
                    'practicality' => (float) ($extendBy * 40),
                    'extend_by' => $extendBy,
                ];
            }
        }

        $splitIncrease = $this->roundMoneyForAdvice($maxReasonableIncrease);
        $splitMonthly = $this->roundMoneyForAdvice($currentMonthly + $splitIncrease);
        $splitOneTime = $this->roundMoneyForAdvice(max(0.0, $remaining - ($splitMonthly * $monthsToDeadline)));
        $simSplit = $this->goalWhatIfService->simulate([
            'current_saved' => $currentSaved,
            'target_amount' => $targetAmount,
            'deadline_date' => $deadlineDate,
            'today_date' => $todayDate,
            'monthly_deposit' => $splitMonthly,
            'one_time_deposit' => $baseOneTime + $splitOneTime,
        ]);
        $splitGap = (int) ($simSplit['deadline_gap_months'] ?? $gap);
        $splitConf = (float) ($simSplit['deadline_confidence'] ?? $confidence);
        $candidates['split'] = [
            'key' => 'split',
            'title' => 'Split strategy',
            'details' => sprintf(
                'Increase monthly by +%.2f TND to %.2f TND/month and add %.2f TND one-time; %s',
                $splitIncrease,
                $splitMonthly,
                $splitOneTime,
                $this->gapOutcomeText($gap, $splitGap, $confidence, $splitConf)
            ),
            'next_7_days' => sprintf(
                'Set automatic transfer to %.2f TND and schedule a one-time top-up of %.2f TND this week.',
                $splitMonthly,
                $splitOneTime
            ),
            'practicality' => $splitIncrease + $splitOneTime + 25.0,
        ];

        $requiredTooHigh = $requiredAdjustment > $maxReasonableIncrease;
        $bestKey = 'split';
        if ($requiredAdjustment > 0.0 && $requiredAdjustment <= $maxReasonableIncrease) {
            $bestKey = 'monthly';
        } elseif (($candidates['one_time']['reasonable'] ?? false) === true) {
            $bestKey = 'one_time';
        } elseif (
            isset($candidates['deadline']) &&
            (
                (int) ($candidates['deadline']['extend_by'] ?? 999) <= self::MAX_REASONABLE_DEADLINE_EXTENSION ||
                $requiredTooHigh ||
                $scenarioType === 'deadline_adjustment'
            )
        ) {
            $bestKey = 'deadline';
        }

        if (!isset($candidates[$bestKey])) {
            $bestKey = 'split';
        }
        $bestRaw = $candidates[$bestKey];
        unset($candidates[$bestKey]);

        usort($candidates, static fn(array $a, array $b): int => ((float) ($a['practicality'] ?? 999999.0)) <=> ((float) ($b['practicality'] ?? 999999.0)));
        $alternatives = [];
        foreach (array_slice($candidates, 0, 2) as $alt) {
            $alternatives[] = [
                'title' => (string) ($alt['title'] ?? 'Alternative'),
                'details' => (string) ($alt['details'] ?? '-'),
            ];
        }

        return [
            'best_action' => [
                'title' => (string) ($bestRaw['title'] ?? 'Best action'),
                'details' => (string) ($bestRaw['details'] ?? '-'),
            ],
            'alternatives' => $alternatives,
            'next_7_days' => (string) ($bestRaw['next_7_days'] ?? 'Set one automation and rerun simulation in 7 days.'),
        ];
    }

    private function roundMoneyForAdvice(float $amount, int $step = 5): float
    {
        $amount = max(0.0, $amount);
        if ($step <= 0) {
            return round($amount, 2);
        }
        return round((round($amount / $step) * $step), 2);
    }

    private function projectedText(mixed $date): string
    {
        if (!is_string($date) || trim($date) === '') {
            return 'no projected finish date';
        }
        return $date;
    }

    private function gapOutcomeText(int $beforeGap, int $afterGap, float $beforeConfidence, float $afterConfidence): string
    {
        if ($afterGap < $beforeGap) {
            return sprintf(
                'reduces deadline gap from %d to %d month(s) and changes confidence from %.0f%% to %.0f%%.',
                $beforeGap,
                $afterGap,
                $beforeConfidence,
                $afterConfidence
            );
        }

        if ($afterGap === $beforeGap) {
            return sprintf(
                'keeps deadline gap at %d month(s) but changes confidence from %.0f%% to %.0f%%.',
                $beforeGap,
                $beforeConfidence,
                $afterConfidence
            );
        }

        return sprintf(
            'changes deadline gap from %d to %d month(s) and confidence from %.0f%% to %.0f%%.',
            $beforeGap,
            $afterGap,
            $beforeConfidence,
            $afterConfidence
        );
    }
}
