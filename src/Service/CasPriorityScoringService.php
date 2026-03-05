<?php

namespace App\Service;

use App\Entity\CasRelles;

class CasPriorityScoringService
{
    /**
     * @return array{
     *   score:int,
     *   level:string,
     *   badgeClass:string,
     *   frequency:int
     * }
     */
    public function score(CasRelles $cas, int $historicalFrequency = 0): array
    {
        $amount = max(0.0, (float) $cas->getMontant());
        $type = (string) $cas->getType();
        $category = strtoupper(trim((string) ($cas->getCategorie() ?? 'AUTRE')));
        if ($category === '') {
            $category = 'AUTRE';
        }

        // Amount contributes up to 45 points.
        $amountScore = (int) min(45, round($amount / 20.0));

        // Negative incidents are more urgent for admin processing.
        $typeScore = $type === CasRelles::TYPE_NEGATIF ? 20 : 5;

        $categoryScore = match ($category) {
            'SANTE' => 20,
            'PANNE_MAISON' => 16,
            'VOITURE' => 14,
            'FACTURES' => 12,
            'ELECTRONIQUE' => 10,
            'EDUCATION' => 8,
            default => 6,
        };

        // Historical repeat frequency contributes up to 15 points.
        $frequency = max(0, $historicalFrequency);
        $frequencyScore = min(15, $frequency * 3);

        $score = max(0, min(100, $amountScore + $typeScore + $categoryScore + $frequencyScore));

        if ($score >= 70) {
            $level = 'HIGH';
            $badgeClass = 'bg-danger';
        } elseif ($score >= 40) {
            $level = 'MEDIUM';
            $badgeClass = 'bg-warning text-dark';
        } else {
            $level = 'LOW';
            $badgeClass = 'bg-success';
        }

        return [
            'score' => $score,
            'level' => $level,
            'badgeClass' => $badgeClass,
            'frequency' => $frequency,
        ];
    }
}
