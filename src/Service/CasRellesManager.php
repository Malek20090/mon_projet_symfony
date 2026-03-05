<?php

namespace App\Service;

use App\Entity\CasRelles;

class CasRellesManager
{
    public function validate(CasRelles $cas): bool
    {
        $titre = trim((string) $cas->getTitre());
        if ($titre === '') {
            throw new \InvalidArgumentException('Le titre est obligatoire.');
        }

        $montant = $cas->getMontant();
        if ($montant === null || $montant <= 0) {
            throw new \InvalidArgumentException('Le montant doit etre strictement positif.');
        }

        return true;
    }
}
