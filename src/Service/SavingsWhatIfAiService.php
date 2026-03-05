<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SavingsWhatIfAiService
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    private function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }

        $fromEnv = (string) ($_ENV[$name] ?? '');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $fromServer = (string) ($_SERVER[$name] ?? '');
        if ($fromServer !== '') {
            return $fromServer;
        }

        return $default;
    }

    private function envFloat(string $name, float $default): float
    {
        $raw = $this->env($name, (string) $default);
        if (!is_numeric($raw)) {
            return $default;
        }

        return (float) $raw;
    }

    private function pickStyleVariant(): string
    {
        $styles = [
            'Write in a concise strategist tone with direct action verbs.',
            'Write like a financial coach: pragmatic, specific, and motivating.',
            'Write like a portfolio advisor: risk-first and scenario-driven.',
            'Write like a turnaround consultant: prioritize highest-impact moves first.',
        ];

        $idx = random_int(0, count($styles) - 1);
        return $styles[$idx];
    }

    /** @phpstan-ignore-next-line */
    private function boolFromContext(array $context, string $key, bool $default = false): bool
    {
        $v = $context[$key] ?? $default;
        if (is_bool($v)) {
            return $v;
        }
        if (is_string($v)) {
            return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
        }
        if (is_numeric($v)) {
            return ((int) $v) !== 0;
        }
        return $default;
    }

    private function textFromContext(array $context, string $key, string $default = ''): string
    {
        $v = $context[$key] ?? $default;
        if (!is_string($v)) {
            return $default;
        }
        $v = trim($v);
        return $v === '' ? $default : $v;
    }

    public function buildAdvice(array $context): array
    {
        $apiKey = $this->env('OPENAI_API_KEY');
        if ($apiKey === '') {
            return [
                'ok' => false,
                'source' => 'fallback',
                'text' => null,
                'model' => null,
                'error' => 'OPENAI_API_KEY is missing.',
            ];
        }

        $model = $this->env('OPENAI_WHATIF_MODEL', $this->env('OPENAI_MODEL', 'gpt-4o-mini'));
        $endpoint = $this->env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions');

        $temperature = $this->envFloat('OPENAI_TEMPERATURE', 0.72);
        $temperature = max(0.2, min(1.1, $temperature));
        $styleVariant = $this->pickStyleVariant();
        $runNonce = (string) random_int(1000, 9999);

        $scenario = is_array($context['scenario'] ?? null) ? $context['scenario'] : [];
        $scenarioType = $this->textFromContext($scenario, 'type', $this->textFromContext($context, 'scenarioType', 'change_monthly_deposit'));
        $runId = $this->textFromContext($scenario, 'runId', 'n/a');
        $monthlyDeposit = (float) ($scenario['monthlyDeposit'] ?? 0.0);
        $oneTimeDeposit = (float) ($scenario['oneTimeDeposit'] ?? 0.0);

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'top_p' => 0.95,
            'frequency_penalty' => 0.35,
            'presence_penalty' => 0.25,
            'max_tokens' => 900,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a senior financial strategy co-pilot for Savings & Goals simulations.\n"
                        . "Output must be decision-grade, numeric, and execution-oriented.\n"
                        . "Use only provided context data. Never invent user-specific facts.",
                ],
                [
                    'role' => 'user',
                    'content' => "Analyze this savings what-if context and produce a high-value response with this exact structure:\n"
                        . "Executive Insight:\n"
                        . "- 2 lines max, both numeric and tied to finish date/risk/feasibility.\n"
                        . "Quantified Improvement Path:\n"
                        . "- 2 to 3 bullets using acceleration gain, required adjustment, and exact TND/month values.\n"
                        . "Risk Interpretation:\n"
                        . "- 2 bullets using stress index, overcommitment, and deadline risk.\n"
                        . "Strategic Action:\n"
                        . "- 1 to 2 precise actions only.\n\n"
                        . "Rules:\n"
                        . "- Do not invent missing data.\n"
                        . "- Every recommendation must reference at least one numeric metric from context.\n"
                        . "- Avoid generic advice like 'reduce spending 10-15%' unless category + amount is provided.\n"
                        . "- If stress index > 70, prioritize sustainability tone.\n"
                        . "- If acceleration gain >= 3 months, prioritize optimization tone.\n"
                        . "- If feasibility score < 60, prioritize realism tone.\n"
                        . "- Keep response compact (~130-210 words).\n"
                        . "- Use a different opening sentence and phrasing style each run.\n\n"
                        . "Language rule:\n"
                        . "- Match language style from context/user.\n\n"
                        . "Style variant for this run:\n"
                        . "- {$styleVariant}\n\n"
                        . "Scenario snapshot:\n"
                        . "- Scenario type: {$scenarioType}\n"
                        . "- Run id: {$runId}\n"
                        . "- Variation nonce: {$runNonce}\n"
                        . "- Monthly deposit: {$monthlyDeposit}\n"
                        . "- One-time deposit: {$oneTimeDeposit}\n"
                        . "- Scenario should be evaluated against current goals and deadlines.\n\n"
                        . "Input context JSON:\n"
                        . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                ],
            ],
        ];

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 20,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'source' => 'fallback',
                'text' => null,
                'model' => $model,
                'error' => $e->getMessage(),
            ];
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $apiError = (string) ($data['error']['message'] ?? ('OpenAI API HTTP ' . $status));
            return [
                'ok' => false,
                'source' => 'fallback',
                'text' => null,
                'model' => $model,
                'error' => $apiError,
            ];
        }

        $text = (string) ($data['choices'][0]['message']['content'] ?? '');
        $text = trim($text);

        if ($text === '') {
            return [
                'ok' => false,
                'source' => 'fallback',
                'text' => null,
                'model' => $model,
                'error' => 'OpenAI returned an empty answer.',
            ];
        }

        return [
            'ok' => true,
            'source' => 'openai',
            'text' => $text,
            'model' => $model,
            'error' => null,
        ];
    }
}
