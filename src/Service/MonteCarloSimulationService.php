<?php

namespace App\Service;

use App\Entity\Objectif;

class MonteCarloSimulationService
{
    private InvestissementCalculatorService $calculator;

    public function __construct(InvestissementCalculatorService $calculator)
    {
        $this->calculator = $calculator;
    }

    public function simulate(Objectif $objectif, int $days = 180, int $simulations = 1000): array
    {
        $currentValue = $this->calculator->calculateCurrentAmountForObjectif($objectif);
        $target = $objectif->getTargetAmount();

        $successCount = 0;
        $finalValues = [];

        // tableau pour stocker la somme de chaque jour
        $dailyAverages = array_fill(0, $days, 0);

        for ($i = 0; $i < $simulations; $i++) {

            $value = $currentValue;

            for ($d = 0; $d < $days; $d++) {

                $volatility = 0.02; // 2% daily volatility

                $randomPercent = (mt_rand(-100, 100) / 100) * $volatility;

                $value *= (1 + $randomPercent);

                // on additionne pour calculer la moyenne plus tard
                $dailyAverages[$d] += $value;
            }

            $finalValues[] = $value;

            if ($value >= $target) {
                $successCount++;
            }
        }

        // calcul moyenne réelle
        for ($d = 0; $d < $days; $d++) {
            $dailyAverages[$d] = round($dailyAverages[$d] / $simulations, 2);
        }

        $probability = ($successCount / $simulations) * 100;

        return [
            'probability' => round($probability, 2),
            'bestCase' => round(max($finalValues), 2),
            'worstCase' => round(min($finalValues), 2),
            'dailyEvolution' => $dailyAverages,
            'target' => $target,
            'values' => $finalValues, // Array of final simulation results for histogram
        ];
    }
}