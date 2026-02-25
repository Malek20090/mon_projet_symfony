<?php

namespace App\Service;

final class GoalWhatIfService
{
    /**
     * @param array{
     *   current_saved: float,
     *   target_amount: float,
     *   deadline_date: ?string,
     *   today_date: string,
     *   monthly_deposit: float,
     *   one_time_deposit: float
     * } $input
     * @return array{
     *   months_to_finish:?int,
     *   projected_finish_date:?string,
     *   remaining_after_now:float,
     *   required_monthly_to_hit_deadline:float,
     *   deadline_gap_months:int,
     *   months_to_deadline:int,
     *   risk_level:string,
     *   feasibility_score:float,
     *   deadline_confidence:float
     * }
     */
    public function simulate(array $input): array
    {
        $currentSaved = max(0.0, (float) ($input['current_saved'] ?? 0.0));
        $targetAmount = max(0.0, (float) ($input['target_amount'] ?? 0.0));
        $deadlineDate = isset($input['deadline_date']) && is_string($input['deadline_date']) && trim($input['deadline_date']) !== ''
            ? trim((string) $input['deadline_date'])
            : null;
        $todayDate = (string) ($input['today_date'] ?? date('Y-m-d'));
        $monthlyDeposit = max(0.0, (float) ($input['monthly_deposit'] ?? 0.0));
        $oneTimeDeposit = max(0.0, (float) ($input['one_time_deposit'] ?? 0.0));

        $today = new \DateTimeImmutable($todayDate);
        $deadline = null;
        if ($deadlineDate !== null) {
            try {
                $deadline = new \DateTimeImmutable($deadlineDate);
            } catch (\Throwable $e) {
                $deadline = null;
            }
        }

        $remainingAfterNow = max(0.0, $targetAmount - ($currentSaved + $oneTimeDeposit));
        $monthsToFinish = null;
        if ($remainingAfterNow <= 0.0) {
            $monthsToFinish = 0;
        } elseif ($monthlyDeposit > 0.0) {
            $monthsToFinish = (int) ceil($remainingAfterNow / $monthlyDeposit);
        }

        $projectedFinishDate = null;
        if ($monthsToFinish !== null) {
            $projectedFinishDate = $today->modify('+' . $monthsToFinish . ' month')->format('Y-m-d');
        }

        $monthsToDeadline = 0;
        $requiredMonthly = 0.0;
        if ($deadline instanceof \DateTimeImmutable) {
            $monthsToDeadline = $this->monthsDiffCeil($today, $deadline);
            if ($remainingAfterNow <= 0.0) {
                $requiredMonthly = 0.0;
            } elseif ($monthsToDeadline > 0) {
                $requiredMonthly = round($remainingAfterNow / $monthsToDeadline, 2);
            } else {
                $requiredMonthly = round($remainingAfterNow, 2);
            }
        }

        $deadlineGapMonths = 0;
        if ($deadline instanceof \DateTimeImmutable) {
            if ($projectedFinishDate === null) {
                $deadlineGapMonths = max(3, $monthsToDeadline + 3);
            } else {
                $projected = new \DateTimeImmutable($projectedFinishDate);
                if ($projected > $deadline) {
                    $deadlineGapMonths = $this->monthsDiffCeil($deadline, $projected);
                }
            }
        }

        $riskLevel = 'LOW';
        if ($deadlineGapMonths > 2) {
            $riskLevel = 'HIGH';
        } elseif ($deadlineGapMonths >= 1) {
            $riskLevel = 'MED';
        } elseif ($projectedFinishDate === null && $remainingAfterNow > 0.0) {
            $riskLevel = 'HIGH';
        }

        $feasibilityScore = 100.0;
        if ($riskLevel === 'HIGH') {
            $feasibilityScore -= 25.0;
        } elseif ($riskLevel === 'MED') {
            $feasibilityScore -= 10.0;
        }

        if ($requiredMonthly > 0.0) {
            $distanceRatio = ($requiredMonthly - $monthlyDeposit) / max(1.0, $requiredMonthly);
            if ($distanceRatio > 0.0) {
                $feasibilityScore -= min(60.0, $distanceRatio * 60.0);
            }
        }

        if ($monthlyDeposit <= 0.0 && $remainingAfterNow > 0.0) {
            $feasibilityScore -= 20.0;
        }
        $feasibilityScore = max(0.0, min(100.0, round($feasibilityScore, 1)));

        $deadlineConfidence = 100.0;
        if ($riskLevel === 'LOW') {
            $deadlineConfidence = 100.0;
        } elseif ($riskLevel === 'MED') {
            $deadlineConfidence = 70.0 - (max(0, $deadlineGapMonths - 1) * 15.0);
        } else {
            $deadlineConfidence = max(10.0, 40.0 - (max(0, $deadlineGapMonths - 3) * 5.0));
        }
        $deadlineConfidence = max(0.0, min(100.0, round($deadlineConfidence, 1)));

        return [
            'months_to_finish' => $monthsToFinish,
            'projected_finish_date' => $projectedFinishDate,
            'remaining_after_now' => round($remainingAfterNow, 2),
            'required_monthly_to_hit_deadline' => round($requiredMonthly, 2),
            'deadline_gap_months' => $deadlineGapMonths,
            'months_to_deadline' => $monthsToDeadline,
            'risk_level' => $riskLevel,
            'feasibility_score' => $feasibilityScore,
            'deadline_confidence' => $deadlineConfidence,
        ];
    }

    private function monthsDiffCeil(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }
        $days = (int) $from->diff($to)->format('%a');
        return (int) ceil($days / 30.44);
    }
}

