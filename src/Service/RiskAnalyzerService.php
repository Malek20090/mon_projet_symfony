<?php

namespace App\Service;

use App\Entity\CasRelles;

class RiskAnalyzerService
{
    /**
     * @var string[]
     */
    private const CATEGORIES = ['VOITURE', 'PANNE_MAISON', 'SANTE', 'EDUCATION', 'FACTURES', 'AUTRE'];

    /**
     * @param CasRelles[] $cases
     * @return array{
     *   typology: array{
     *     counts: array<string,int>,
     *     percentages: array<string,float>,
     *     total: int,
     *     topCategory: string,
     *     topPercent: float,
     *     exposureMessage: string
     *   },
     *   riskScore: int,
     *   riskLevel: string,
     *   weeklyTips: string[],
     *   suggestedIncidents: string[],
     *   suggestedOpportunities: string[]
     * }
     */
    public function analyze(array $cases): array
    {
        $counts = array_fill_keys(self::CATEGORIES, 0);
        $amounts = array_fill_keys(self::CATEGORIES, 0.0);

        $negativeCount = 0;
        $negativeAmount = 0.0;
        $recentCounts = array_fill_keys(self::CATEGORIES, 0);
        $recentAmounts = array_fill_keys(self::CATEGORIES, 0.0);
        $recentNegativeCount = 0;

        $weightedFrequency = 0.0;
        $weightedSeverity = 0.0;

        foreach ($cases as $case) {
            if ((string) $case->getType() !== CasRelles::TYPE_NEGATIF) {
                continue;
            }

            $negativeCount++;
            $amount = (float) $case->getMontant();
            $negativeAmount += $amount;

            $text = mb_strtolower(trim(((string) $case->getTitre()) . ' ' . ((string) $case->getDescription())));
            $category = $this->categorizeText($text);
            $counts[$category]++;
            $amounts[$category] += $amount;

            $days = $this->daysSince($case);
            if ($days <= 30) {
                $recentCounts[$category]++;
                $recentAmounts[$category] += $amount;
                $recentNegativeCount++;
            }

            // Newer events should impact the risk model more.
            $weight = max(0.20, 1.0 - ($days / 180.0));
            $weightedFrequency += (10.0 * $weight);
            $weightedSeverity += (($amount / 20.0) * $weight);
        }

        $totalTyped = array_sum($counts);
        $percentages = [];
        foreach ($counts as $key => $value) {
            $percentages[$key] = $totalTyped > 0 ? ($value * 100 / $totalTyped) : 0.0;
        }

        $sorted = $counts;
        arsort($sorted);
        $topCategory = (string) array_key_first($sorted);
        $topPercent = $percentages[$topCategory] ?? 0.0;

        $labels = [
            'VOITURE' => 'automobiles',
            'PANNE_MAISON' => 'de pannes maison',
            'SANTE' => 'de sante',
            'EDUCATION' => 'd education',
            'FACTURES' => 'de factures et charges',
            'AUTRE' => 'divers',
        ];
        $exposureMessage = $totalTyped > 0
            ? sprintf('Tu es fortement expose aux risques %s.', $labels[$topCategory] ?? 'divers')
            : 'Pas assez de donnees pour definir un risque dominant.';

        $avgAmount = $negativeCount > 0 ? ($negativeAmount / $negativeCount) : 0.0;
        $frequencyScore = min(50, (int) round($weightedFrequency));
        $severityScore = min(50, (int) round($weightedSeverity));
        $riskScore = max(0, min(100, $frequencyScore + $severityScore));

        if ($riskScore >= 70) {
            $riskLevel = 'ELEVE';
        } elseif ($riskScore >= 40) {
            $riskLevel = 'MOYEN';
        } else {
            $riskLevel = 'FAIBLE';
        }

        return [
            'typology' => [
                'counts' => $counts,
                'percentages' => $percentages,
                'total' => $totalTyped,
                'topCategory' => $topCategory,
                'topPercent' => $topPercent,
                'exposureMessage' => $exposureMessage,
            ],
            'riskScore' => $riskScore,
            'riskLevel' => $riskLevel,
            'weeklyTips' => $this->buildWeeklyTips($recentCounts, $recentAmounts, $recentNegativeCount, $avgAmount),
            'suggestedIncidents' => $this->buildSuggestedIncidents($recentCounts, $counts),
            'suggestedOpportunities' => $this->buildSuggestedOpportunities($recentCounts, $counts),
        ];
    }

    private function categorizeText(string $text): string
    {
        if ($text === '') {
            return 'AUTRE';
        }

        if (preg_match('/panne moteur|essuie|essuie-glace|batterie|pneu|voiture|auto|garage|carburant|essence|accident|frein/u', $text)) {
            return 'VOITURE';
        }
        if (preg_match('/maison|logement|loyer|toit|plomberie|electricite|fuite|salle de bain|wc|canalisation|machine [aÃ ] laver|frigo|chaudiere|electromenager|panne/u', $text)) {
            return 'PANNE_MAISON';
        }
        if (preg_match('/urgence|medicament|consultation|analyse|sante|hopital|pharmacie|soin|maladie|malade|grippe|fievre/u', $text)) {
            return 'SANTE';
        }
        if (preg_match('/ecole|universite|formation|inscription|frais scolaire|education|cours|etude|bourse/u', $text)) {
            return 'EDUCATION';
        }
        if (preg_match('/facture|eau|electricite|gaz|internet|credit|banque|taxe|impot|abonnement/u', $text)) {
            return 'FACTURES';
        }

        return 'AUTRE';
    }

    /**
     * @param array<string,int> $counts
     * @param array<string,float> $amounts
     * @return string[]
     */
    private function buildWeeklyTips(array $counts, array $amounts, int $negativeCount, float $avgAmount): array
    {
        $tips = [];

        $carCount = (int) ($counts['VOITURE'] ?? 0);
        if ($carCount >= 2) {
            $carAvg = ((float) ($amounts['VOITURE'] ?? 0.0)) / max(1, $carCount);
            $carBudget = (int) (round(max(80.0, min(300.0, $carAvg * 0.40)) / 10) * 10);
            $tips[] = sprintf('Les cas voiture se repetent (%d). Programme un entretien chaque mois et reserve %d DT/mois.', $carCount, $carBudget);
        } elseif ($carCount === 1) {
            $tips[] = 'Tu as eu un incident voiture. Surveille 30 jours, et si ca se repete passe a un entretien mensuel.';
        }

        $healthCount = (int) ($counts['SANTE'] ?? 0);
        if ($healthCount >= 2) {
            $healthAvg = ((float) ($amounts['SANTE'] ?? 0.0)) / max(1, $healthCount);
            $healthBudget = (int) (round(max(40.0, min(180.0, $healthAvg * 0.35)) / 10) * 10);
            $tips[] = sprintf('Les cas sante/maladie se repetent (%d). Fais un bilan/controle chaque mois et prevois %d DT.', $healthCount, $healthBudget);
        } elseif ($healthCount === 1) {
            $tips[] = 'Tu as eu un cas sante. Si ca revient, mets en place un controle mensuel.';
        }

        $educationCount = (int) ($counts['EDUCATION'] ?? 0);
        if ($educationCount > 0) {
            $tips[] = 'Ajoute une enveloppe education (inscription, cours, fournitures) pour lisser les depenses.';
        }

        $factureCount = (int) ($counts['FACTURES'] ?? 0);
        if ($factureCount > 0) {
            $tips[] = 'Tu as des charges recurrentes: reserve une part fixe mensuelle pour factures et abonnements.';
        }

        if ($negativeCount >= 3) {
            $avgRounded = (int) (round($avgAmount / 10) * 10);
            $tips[] = sprintf('Tu as %d imprevus negatifs recents. Mets en place une enveloppe prevention de %d DT par mois.', $negativeCount, max(60, $avgRounded));
        }

        if ($avgAmount > 200) {
            $tips[] = 'Le cout moyen des imprevus est eleve: renforce progressivement ton fonds de securite.';
        }

        if (!$tips) {
            $tips[] = 'Pas de risque fort detecte: continue le suivi hebdomadaire pour garder cette stabilite.';
        }

        return array_slice(array_values(array_unique($tips)), 0, 4);
    }

    /**
     * @param array<string,int> $counts
     * @return string[]
     */
    private function buildSuggestedIncidents(array $recentCounts, array $allCounts): array
    {
        $suggestions = [];

        $car = max((int) ($recentCounts['VOITURE'] ?? 0), (int) floor(((int) ($allCounts['VOITURE'] ?? 0)) / 2));
        if ($car >= 1) {
            $suggestions[] = 'Panne moteur voiture';
            $suggestions[] = 'Panne essuie-glace';
            $suggestions[] = 'Batterie voiture';
            $suggestions[] = 'Pneu voiture';
        }

        $home = max((int) ($recentCounts['PANNE_MAISON'] ?? 0), (int) floor(((int) ($allCounts['PANNE_MAISON'] ?? 0)) / 2));
        if ($home >= 1) {
            $suggestions[] = 'Fuite eau salle de bain';
            $suggestions[] = 'Panne machine a laver';
            $suggestions[] = 'Panne frigo';
            $suggestions[] = 'Panne chauffe-eau';
        }

        $health = max((int) ($recentCounts['SANTE'] ?? 0), (int) floor(((int) ($allCounts['SANTE'] ?? 0)) / 2));
        if ($health >= 1) {
            $suggestions[] = 'Urgence sante';
            $suggestions[] = 'Medicament';
            $suggestions[] = 'Consultation';
            $suggestions[] = 'Analyses';
        }

        $education = max((int) ($recentCounts['EDUCATION'] ?? 0), (int) floor(((int) ($allCounts['EDUCATION'] ?? 0)) / 2));
        if ($education >= 1) {
            $suggestions[] = 'Frais inscription scolaire';
            $suggestions[] = 'Cours de soutien';
            $suggestions[] = 'Achat fournitures';
        }

        $factures = max((int) ($recentCounts['FACTURES'] ?? 0), (int) floor(((int) ($allCounts['FACTURES'] ?? 0)) / 2));
        if ($factures >= 1) {
            $suggestions[] = 'Facture eau/electricite';
            $suggestions[] = 'Facture internet imprevue';
            $suggestions[] = 'Echeance credit';
        }

        return array_values(array_unique($suggestions));
    }

    /**
     * @param array<string,int> $counts
     * @return string[]
     */
    private function buildSuggestedOpportunities(array $recentCounts, array $allCounts): array
    {
        $ops = [];

        $car = max((int) ($recentCounts['VOITURE'] ?? 0), (int) floor(((int) ($allCounts['VOITURE'] ?? 0)) / 2));
        if ($car >= 1) {
            $ops[] = 'Opportunite: forfait entretien voiture preventif';
        }

        $home = max((int) ($recentCounts['PANNE_MAISON'] ?? 0), (int) floor(((int) ($allCounts['PANNE_MAISON'] ?? 0)) / 2));
        if ($home >= 1) {
            $ops[] = 'Opportunite: extension de garantie electromenager';
        }

        $health = max((int) ($recentCounts['SANTE'] ?? 0), (int) floor(((int) ($allCounts['SANTE'] ?? 0)) / 2));
        if ($health >= 1) {
            $ops[] = 'Opportunite: pack prevention sante et pharmacie';
        }

        $education = max((int) ($recentCounts['EDUCATION'] ?? 0), (int) floor(((int) ($allCounts['EDUCATION'] ?? 0)) / 2));
        if ($education >= 1) {
            $ops[] = 'Opportunite: bourse, reduction de frais ou aide etudiante';
        }

        $factures = max((int) ($recentCounts['FACTURES'] ?? 0), (int) floor(((int) ($allCounts['FACTURES'] ?? 0)) / 2));
        if ($factures >= 1) {
            $ops[] = 'Opportunite: renegociation des abonnements et charges fixes';
        }

        return $ops;
    }

    private function daysSince(CasRelles $case): int
    {
        $date = $case->getDateEffet();
        if (!$date) {
            return 999;
        }

        $now = new \DateTimeImmutable();
        $caseDate = \DateTimeImmutable::createFromMutable(
            $date instanceof \DateTime ? $date : new \DateTime($date->format('Y-m-d H:i:s'))
        );

        return max(0, (int) $caseDate->diff($now)->format('%a'));
    }
}
