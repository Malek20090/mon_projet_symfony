<?php

namespace App\Service;

use App\Entity\CasRelles;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CasDecisionAiService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $openAiApiKey = null,
        private readonly string $openAiModel = 'gpt-4o-mini'
    ) {
    }

    /**
     * @param array{
     *   has_objectif?: bool,
     *   objectif_current?: float|null,
     *   objectif_target?: float|null,
     *   security_fund_balance?: float|null,
     *   reason_hint?: string,
     *   near_goal?: bool
     * } $context
     */
    public function generateRefusalReason(CasRelles $cas, array $context = []): string
    {
        $proposal = $this->proposeDecision($cas, $context);
        $localReason = (string) ($proposal['refusal_reason'] ?? $this->buildLocalReason($cas, $context));

        $key = trim((string) $this->openAiApiKey);
        if ($key === '') {
            return $localReason;
        }

        try {
            $payload = [
                'case' => [
                    'title' => $cas->getTitre(),
                    'type' => $cas->getType(),
                    'amount' => (float) $cas->getMontant(),
                    'selected_solution' => $cas->getSolution(),
                ],
                'context' => $context,
                'base_reason' => $localReason,
            ];

            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->openAiModel,
                    'temperature' => 0.1,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a strict financial admin assistant. Return one short refusal reason in French, clear and professional, max 35 words.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode($payload, JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray(false);
            $reason = trim((string) ($data['choices'][0]['message']['content'] ?? ''));

            return $reason !== '' ? $reason : $localReason;
        } catch (\Throwable) {
            return $localReason;
        }
    }

    /**
     * @param array{
     *   has_objectif?: bool,
     *   objectif_current?: float|null,
     *   objectif_target?: float|null,
     *   security_fund_balance?: float|null,
     *   reason_hint?: string,
     *   near_goal?: bool
     * } $context
     *
     * @return array{
     *   recommended_action: string,
     *   recommended_solution: string,
     *   rationale: string,
     *   refusal_reason: string|null,
     *   confidence: int
     * }
     */
    public function proposeDecision(CasRelles $cas, array $context = []): array
    {
        $type = (string) $cas->getType();
        $amount = (float) $cas->getMontant();
        $selectedSolution = $this->normalizeSolution((string) $cas->getSolution());
        $hasObjectif = (bool) ($context['has_objectif'] ?? false);
        $objectifCurrent = isset($context['objectif_current']) ? (float) $context['objectif_current'] : 0.0;
        $objectifTarget = isset($context['objectif_target']) ? (float) $context['objectif_target'] : 0.0;
        $fundBalance = isset($context['security_fund_balance']) ? (float) $context['security_fund_balance'] : 0.0;
        $nearGoal = (bool) ($context['near_goal'] ?? false);

        if ($type !== CasRelles::TYPE_NEGATIF) {
            $proposal = [
                'recommended_action' => 'accept',
                'recommended_solution' => $selectedSolution !== '' ? $selectedSolution : CasRelles::SOLUTION_FONDS_SECURITE,
                'rationale' => "Cas positif: acceptation recommandee pour renforcer la capacite financiere.",
                'refusal_reason' => null,
                'confidence' => 92,
            ];

            return $this->enhanceProposalWithAi($cas, $context, $proposal);
        }

        $fundEnough = $fundBalance >= $amount;
        $objectifEnough = $hasObjectif && $objectifCurrent >= $amount;
        $nearGoalEffective = $nearGoal && $objectifEnough;

        // Hard guardrails: insufficiency always wins over "near goal".
        if ($selectedSolution === CasRelles::SOLUTION_OBJECTIF && !$objectifEnough) {
            $proposal = [
                'recommended_action' => 'reject',
                'recommended_solution' => $fundEnough ? CasRelles::SOLUTION_FONDS_SECURITE : CasRelles::SOLUTION_FAMILLE,
                'rationale' => $fundEnough
                    ? "Objectif insuffisant; fonds de securite disponible."
                    : "Objectif et fonds de securite insuffisants.",
                'refusal_reason' => $fundEnough
                    ? sprintf("Refus: solde actuel de l'objectif insuffisant (%.2f DT) pour %.2f DT. Solution recommandee: FONDS_SECURITE.", $objectifCurrent, $amount)
                    : sprintf("Refus: objectif (%.2f DT) et fonds de securite (%.2f DT) insuffisants pour %.2f DT. Solution recommandee: FAMILLE.", $objectifCurrent, $fundBalance, $amount),
                'confidence' => 96,
            ];

            return $this->enhanceProposalWithAi($cas, $context, $proposal);
        }

        if ($selectedSolution === CasRelles::SOLUTION_FONDS_SECURITE && !$fundEnough) {
            $proposal = [
                'recommended_action' => 'reject',
                'recommended_solution' => $objectifEnough ? CasRelles::SOLUTION_OBJECTIF : CasRelles::SOLUTION_FAMILLE,
                'rationale' => $objectifEnough
                    ? "Fonds de securite insuffisant; objectif disponible."
                    : "Fonds de securite et objectif insuffisants.",
                'refusal_reason' => $objectifEnough
                    ? sprintf("Refus: fonds de securite insuffisant (%.2f DT) pour %.2f DT. Solution recommandee: OBJECTIF.", $fundBalance, $amount)
                    : sprintf("Refus: fonds de securite (%.2f DT) et objectif (%.2f DT) insuffisants pour %.2f DT. Solution recommandee: FAMILLE.", $fundBalance, $objectifCurrent, $amount),
                'confidence' => 96,
            ];

            return $this->enhanceProposalWithAi($cas, $context, $proposal);
        }

        $bestSolution = CasRelles::SOLUTION_FAMILLE;
        $rationale = '';

        if (!$fundEnough && !$objectifEnough) {
            $bestSolution = CasRelles::SOLUTION_FAMILLE;
            $rationale = "Ni le fonds de securite ni l'objectif ne couvrent le montant.";
        } elseif ($nearGoalEffective && $fundEnough) {
            $bestSolution = CasRelles::SOLUTION_FONDS_SECURITE;
            $rationale = "Objectif proche du but; il faut le preserver et utiliser le fonds de securite.";
        } elseif ($fundEnough && !$objectifEnough) {
            $bestSolution = CasRelles::SOLUTION_FONDS_SECURITE;
            $rationale = "Le fonds de securite couvre le montant, contrairement a l'objectif.";
        } elseif ($objectifEnough && $fundBalance < ($amount * 0.5)) {
            $bestSolution = CasRelles::SOLUTION_OBJECTIF;
            $rationale = "Le fonds de securite est trop bas; il faut le preserver et utiliser l'objectif.";
        } elseif ($fundEnough) {
            $bestSolution = CasRelles::SOLUTION_FONDS_SECURITE;
            $rationale = "Les deux sources couvrent; le fonds de securite est prioritaire pour l'imprevu.";
        } else {
            $bestSolution = CasRelles::SOLUTION_OBJECTIF;
            $rationale = "L'objectif couvre le besoin et reste la meilleure option disponible.";
        }

        if ($selectedSolution === $bestSolution) {
            $proposal = [
                'recommended_action' => 'accept',
                'recommended_solution' => $bestSolution,
                'rationale' => $rationale,
                'refusal_reason' => null,
                'confidence' => 88,
            ];

            return $this->enhanceProposalWithAi($cas, $context, $proposal);
        }

        $proposal = [
            'recommended_action' => 'reject',
            'recommended_solution' => $bestSolution,
            'rationale' => $rationale,
            'refusal_reason' => sprintf(
                "Refus de la solution demandee (%s): %s Solution recommandee: %s.",
                $selectedSolution !== '' ? $selectedSolution : 'N/A',
                $rationale,
                $bestSolution
            ),
            'confidence' => 84,
        ];

        return $this->enhanceProposalWithAi($cas, $context, $proposal);
    }

    /**
     * Keep deterministic action/solution. AI only refines explanation text.
     *
     * @param array{
     *   recommended_action: string,
     *   recommended_solution: string,
     *   rationale: string,
     *   refusal_reason: string|null,
     *   confidence: int
     * } $proposal
     * @param array<string, mixed> $context
     *
     * @return array{
     *   recommended_action: string,
     *   recommended_solution: string,
     *   rationale: string,
     *   refusal_reason: string|null,
     *   confidence: int
     * }
     */
    private function enhanceProposalWithAi(CasRelles $cas, array $context, array $proposal): array
    {
        $key = trim((string) $this->openAiApiKey);
        if ($key === '') {
            return $proposal;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $key,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->openAiModel,
                    'temperature' => 0.1,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Rewrite rationale and refusal_reason in French. Keep decision and recommended solution unchanged.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'case' => [
                                    'title' => $cas->getTitre(),
                                    'type' => $cas->getType(),
                                    'amount' => (float) $cas->getMontant(),
                                    'selected_solution' => $cas->getSolution(),
                                ],
                                'context' => $context,
                                'proposal' => $proposal,
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'proposal_refinement',
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'rationale' => ['type' => 'string'],
                                    'refusal_reason' => ['type' => ['string', 'null']],
                                ],
                                'required' => ['rationale', 'refusal_reason'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray(false);
            $content = (string) ($data['choices'][0]['message']['content'] ?? '');
            if ($content === '') {
                return $proposal;
            }

            $parsed = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($parsed)) {
                return $proposal;
            }

            $rationale = trim((string) ($parsed['rationale'] ?? ''));
            if ($rationale !== '') {
                $proposal['rationale'] = $rationale;
            }

            $refusalReason = $parsed['refusal_reason'] ?? null;
            if ($proposal['recommended_action'] === 'reject' && is_string($refusalReason) && trim($refusalReason) !== '') {
                $proposal['refusal_reason'] = trim($refusalReason);
            }
        } catch (\Throwable) {
            return $proposal;
        }

        return $proposal;
    }

    /**
     * @param array{
     *   has_objectif?: bool,
     *   objectif_current?: float|null,
     *   objectif_target?: float|null,
     *   security_fund_balance?: float|null,
     *   reason_hint?: string,
     *   near_goal?: bool
     * } $context
     */
    private function buildLocalReason(CasRelles $cas, array $context): string
    {
        $solution = (string) $cas->getSolution();
        $amount = (float) $cas->getMontant();
        $hint = trim((string) ($context['reason_hint'] ?? ''));

        if ($hint !== '') {
            return $hint;
        }

        $solution = $this->normalizeSolution($solution);

        if ($solution === CasRelles::SOLUTION_OBJECTIF) {
            $hasObjectif = (bool) ($context['has_objectif'] ?? false);
            $current = isset($context['objectif_current']) ? (float) $context['objectif_current'] : null;
            if (!$hasObjectif) {
                return "Refus: aucun objectif n'est rattache a ce cas.";
            }
            if ($current !== null && $current < $amount) {
                return sprintf(
                    "Refus: solde actuel de l'objectif insuffisant (%.2f DT) pour couvrir %.2f DT.",
                    $current,
                    $amount
                );
            }
        }

        if ($solution === CasRelles::SOLUTION_FONDS_SECURITE && $cas->getType() === CasRelles::TYPE_NEGATIF) {
            $fund = isset($context['security_fund_balance']) ? (float) $context['security_fund_balance'] : null;
            if ($fund !== null && $fund < $amount) {
                return sprintf(
                    "Refus: fonds de securite insuffisant (%.2f DT) pour un besoin de %.2f DT.",
                    $fund,
                    $amount
                );
            }
        }

        return "Refus: la demande ne respecte pas les criteres de validation financiere et necessite une revision.";
    }

    private function normalizeSolution(string $solution): string
    {
        if ($solution === 'EPARGNE' || $solution === 'COMPTE') {
            return CasRelles::SOLUTION_OBJECTIF;
        }

        return $solution;
    }
}
