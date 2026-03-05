<?php

namespace App\Service;

use App\Entity\Investissement;

class InvestissementValidator
{
    public function validate(Investissement $investissement): bool
    {
        if ($investissement->getAmountInvested() <= 0) {
            throw new \InvalidArgumentException("Le montant investi doit être supérieur à 0");
        }

        if ($investissement->getQuantity() <= 0) {
            throw new \InvalidArgumentException("La quantité doit être supérieure à 0");
        }

        if ($investissement->getBuyPrice() <= 0) {
            throw new \InvalidArgumentException("Le prix d'achat doit être supérieur à 0");
        }

        return true;
    }
}