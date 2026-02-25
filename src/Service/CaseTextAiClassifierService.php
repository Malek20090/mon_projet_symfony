<?php

namespace App\Service;

use App\Entity\CasRelles;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CaseTextAiClassifierService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?string $openAiApiKey = null,
        private readonly string $openAiModel = 'gpt-4o-mini'
    ) {
    }

    /**
     * @return array{
     *   type: string,
     *   category: string,
     *   confidence: int,
     *   source: string
     * }
     */
    public function classify(string $title, ?string $description = null): array
    {
        $text = trim($title . ' ' . (string) $description);
        $local = $this->classifyLocal($text);

        $key = trim((string) $this->openAiApiKey);
        if ($key === '') {
            return $local;
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
                            'content' => 'Classify finance event text and return ONLY JSON with keys: type, category, confidence.',
                        ],
                        [
                            'role' => 'user',
                            'content' => json_encode([
                                'text' => $text,
                                'allowed_type' => [CasRelles::TYPE_POSITIF, CasRelles::TYPE_NEGATIF],
                                'allowed_category' => ['VOITURE', 'PANNE_MAISON', 'ELECTRONIQUE', 'SANTE', 'EDUCATION', 'FACTURES', 'AUTRE'],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
                'timeout' => 8,
            ]);

            $data = $response->toArray(false);
            if (isset($data['error']['message']) && is_string($data['error']['message'])) {
                throw new \RuntimeException('OpenAI API error: ' . $data['error']['message']);
            }

            $rawContent = $data['choices'][0]['message']['content'] ?? '';
            if (is_array($rawContent)) {
                $rawContent = json_encode($rawContent, JSON_UNESCAPED_UNICODE);
            }
            $content = trim((string) $rawContent);
            if ($content === '') {
                throw new \RuntimeException('Empty AI response.');
            }

            $parsed = json_decode($content, true);
            if (!is_array($parsed)) {
                if (preg_match('/\{.*\}/s', $content, $m) === 1) {
                    $parsed = json_decode($m[0], true);
                }
            }
            if (!is_array($parsed)) {
                throw new \RuntimeException('Invalid AI response format. Raw: ' . mb_substr($content, 0, 220));
            }

            $type = (string) ($parsed['type'] ?? '');
            $category = (string) ($parsed['category'] ?? '');
            $confidence = (int) ($parsed['confidence'] ?? 0);

            if (!in_array($type, [CasRelles::TYPE_POSITIF, CasRelles::TYPE_NEGATIF], true)) {
                $type = $local['type'];
            }
            if (!in_array($category, ['VOITURE', 'PANNE_MAISON', 'ELECTRONIQUE', 'SANTE', 'EDUCATION', 'FACTURES', 'AUTRE'], true)) {
                $category = $local['category'];
            }

            return [
                'type' => $type,
                'category' => $category,
                'confidence' => max(0, min(100, $confidence)),
                'source' => 'ai',
            ];
        } catch (\Throwable) {
            return $local;
        }
    }

    /**
     * @return array{type:string,category:string,confidence:int,source:string}
     */
    private function classifyLocal(string $text): array
    {
        $text = mb_strtolower(trim($text));
        if ($text === '') {
            return [
                'type' => CasRelles::TYPE_NEGATIF,
                'category' => 'AUTRE',
                'confidence' => 40,
                'source' => 'local',
            ];
        }

        $negativeTokens = [
            'moins', 'depense', 'dépense', 'facture', 'panne', 'fuite', 'maladie', 'malade',
            'hopital', 'hôpital', 'reparation', 'réparation', 'urgence', 'taxe', 'credit', 'crédit',
        ];
        $positiveTokens = [
            'plus', 'gain', 'prime', 'bonus', 'remboursement', 'vente', 'aide', 'bourse',
            'cadeau', 'revenu', 'salaire', 'commission',
        ];

        $negativeHits = $this->countHits($text, $negativeTokens);
        $positiveHits = $this->countHits($text, $positiveTokens);

        $type = $positiveHits > $negativeHits ? CasRelles::TYPE_POSITIF : CasRelles::TYPE_NEGATIF;
        $confidence = 55 + (int) min(35, abs($positiveHits - $negativeHits) * 10);

        $category = 'AUTRE';
        if (preg_match('/panne moteur|essuie|batterie|pneu|voiture|auto|garage|carburant|essence|accident|frein/u', $text)) {
            $category = 'VOITURE';
            $confidence += 20;
        } elseif (preg_match('/telephone|t[ée]l[ée]phone|pc|ordinateur|laptop|chargeur|ecran|écran|tv|television|télévision|console|electro|électro|mobile/u', $text)) {
            $category = 'ELECTRONIQUE';
            $confidence += 20;
        } elseif (preg_match('/maison|logement|toit|plomberie|electricite|électricit|fuite|salle de bain|wc|canalisation|frigo|chaudiere|chaudière|electromenager|électroménager/u', $text)) {
            $category = 'PANNE_MAISON';
            $confidence += 20;
        } elseif (preg_match('/urgence|medicament|médicament|consultation|analyse|sante|santé|hopital|hôpital|pharmacie|soin|maladie|grippe|fievre|fièvre/u', $text)) {
            $category = 'SANTE';
            $confidence += 20;
        } elseif (preg_match('/ecole|école|universite|université|formation|inscription|frais scolaire|education|éducation|cours|etude|étude|bourse/u', $text)) {
            $category = 'EDUCATION';
            $confidence += 20;
        } elseif (preg_match('/facture|eau|electricite|électricité|gaz|internet|credit|crédit|banque|taxe|impot|impôt|abonnement/u', $text)) {
            $category = 'FACTURES';
            $confidence += 20;
        }

        return [
            'type' => $type,
            'category' => $category,
            'confidence' => max(40, min(95, $confidence)),
            'source' => 'local',
        ];
    }

    /**
     * @param string[] $tokens
     */
    private function countHits(string $text, array $tokens): int
    {
        $hits = 0;
        foreach ($tokens as $token) {
            if (str_contains($text, $token)) {
                $hits++;
            }
        }

        return $hits;
    }
}
