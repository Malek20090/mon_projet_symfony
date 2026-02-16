<?php

namespace App\Service;

use App\Entity\Investissement;
use App\Entity\Objectif;

class InvestissementCalculatorService
{
    /**
     * Calcule le gain ou la perte d’un investissement
     */
    public function calculateGain(Investissement $investissement): float
    {
        $currentValue =
            $investissement->getQuantity()
            * $investissement->getCrypto()->getCurrentprice();

        return $currentValue - $investissement->getAmountInvested();
    }

    /**
     * Trie les investissements selon un critère
     */
    public function sortInvestissements(array $investissements, ?string $sort): array
    {
        if (!$sort) {
            return $investissements;
        }

        usort($investissements, function (
            Investissement $a,
            Investissement $b
        ) use ($sort) {

            return match ($sort) {
                'amount_asc'  => $a->getAmountInvested() <=> $b->getAmountInvested(),
                'amount_desc' => $b->getAmountInvested() <=> $a->getAmountInvested(),

                'gain_asc'  =>
                    $this->calculateGain($a) <=> $this->calculateGain($b),

                'gain_desc' =>
                    $this->calculateGain($b) <=> $this->calculateGain($a),

                default => 0,
            };
        });

        return $investissements;
    }
    /**
 * Filtre les investissements par nom de crypto
 */
public function filterByCryptoName(array $investissements, ?string $search): array
{
    if (!$search) {
        return $investissements;
    }

    $search = strtolower(trim($search));

    return array_filter($investissements, function ($investissement) use ($search) {
        return str_contains(
            strtolower($investissement->getCrypto()->getName()),
            $search
        );
    });
}
/**
 * Calcule le montant actuel d’un objectif
 */
public function calculateCurrentAmountForObjectif(Objectif $objectif): float
{
    $total = 0;

    foreach ($objectif->getInvestissements() as $investissement) {
        $total +=
            $investissement->getQuantity()
            * $investissement->getCrypto()->getCurrentprice();
    }

    return $total;
}
/**
 * Met à jour le statut des objectifs (isCompleted)
 */
public function updateObjectifStatus(iterable $objectifs): void
{
    foreach ($objectifs as $objectif) {
        $currentAmount = $this->calculateCurrentAmountForObjectif($objectif);

        if ($currentAmount >= $objectif->getTargetAmount()) {
            $objectif->setIsCompleted(true);
        } else {
            $objectif->setIsCompleted(false);
        }
    }
}

}

