<?php

namespace App\Service;

use App\Entity\Objectif;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiObjectiveAdvisorService
{
    private HttpClientInterface $client;
    private InvestissementCalculatorService $calculator;

    public function __construct(
        HttpClientInterface $client,
        InvestissementCalculatorService $calculator
    ) {
        $this->client = $client;
        $this->calculator = $calculator;
    }

    public function analyze(Objectif $objectif): array
    {
        // riguel les donnes meloul taa les investissements 
        $summary = $this->buildSummary($objectif);
        //nhadher el prompt bel les donnes 
        $prompt = $this->buildPrompt($summary);
        // envoi el prompt a l'api 
        $response = $this->askAi($prompt);
        //nejbed e score 
        $riskScore = $this->extractRiskScore($response);

        return [
            'content' => $response,
            'riskScore' => $riskScore,
        ];
    }

    private function buildSummary(Objectif $objectif): string
    {
        $initialAmount = $objectif->getInitialAmount();
        $targetAmount = $objectif->getTargetAmount();
        $currentAmount = $this->calculator->calculateCurrentAmountForObjectif($objectif);
        $progress = ($currentAmount / max($targetAmount, 1)) * 100;

        $composition = [];

        foreach ($objectif->getInvestissements() as $investissement) {
            $cryptoName = $investissement->getCrypto()->getSymbol();
            $value = $investissement->getQuantity() *
                     $investissement->getCrypto()->getCurrentprice();

            $composition[$cryptoName] = ($composition[$cryptoName] ?? 0) + $value;
        }

        $total = array_sum($composition);

        $compositionText = "";
        foreach ($composition as $crypto => $value) {
            $percent = ($value / max($total, 1)) * 100;
            $compositionText .= "$crypto: " . round($percent, 2) . "%\n";
        }

        return "
Objective Summary:

Initial amount: $initialAmount USD
Target amount: $targetAmount USD
Current value: " . round($currentAmount, 2) . " USD
Progress: " . round($progress, 2) . "%

Composition:
$compositionText

Target multiplier: x" . $objectif->getTargetMultiplier();
    }

    private function buildPrompt(string $summary): string
{
    return "
You are a professional crypto investment advisor.

Analyze the following objective.

⚠️ IMPORTANT RULES:
- Keep the analysis SHORT (max 12 lines)
- Use bullet points
- Use emojis
- Be clear and structured
- Be concise and modern
- Do NOT write long paragraphs
- Do NOT exceed 200 words

Structure your answer EXACTLY like this:

🎯 Risk Level: ...
📊 Diversification: ...
📈 Target Realism: ...
⏳ Estimated Time: ...
💡 Recommendations:
- ...
- ...
- ...

End with:
Risk Score: XX
the risk score sould be on 100 
Objective Data:
$summary
";
}
private function askAi(string $prompt): string
{
    $apiKey = $_ENV['GROQ_API_KEY'] ?? null;

    if (!$apiKey) {
        return 'Groq API key not configured';
    }

    $response = $this->client->request(
        'POST',
        'https://api.groq.com/openai/v1/chat/completions',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'llama-3.1-8b-instant',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a professional crypto investment advisor.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.7
            ]
        ]
    );

    $data = $response->toArray(false);

    if (isset($data['error'])) {
        return 'Groq error: ' . $data['error']['message'];
    }

    return $data['choices'][0]['message']['content'] ?? 'No response';
}

private function extractRiskScore(string $response): ?int
{
    if (preg_match('/Risk Score:\s*(\d+)/i', $response, $matches)) {
        return (int) $matches[1];
    }

    return null;
}
}