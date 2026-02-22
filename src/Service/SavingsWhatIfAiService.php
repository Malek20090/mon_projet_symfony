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

        $persona = strtolower($this->textFromContext($context, 'persona', 'balanced'));
        if (!in_array($persona, ['conservative', 'balanced', 'aggressive'], true)) {
            $persona = 'balanced';
        }
        $brutalTruth = $this->boolFromContext($context, 'brutalTruth', false);
        $scenario = is_array($context['scenario'] ?? null) ? $context['scenario'] : [];
        $monthlyDeposit = (float) ($scenario['monthlyDeposit'] ?? 0.0);
        $oneTimeDeposit = (float) ($scenario['oneTimeDeposit'] ?? 0.0);
        $stayLazyMode = ($monthlyDeposit <= 0.0 && $oneTimeDeposit <= 0.0);

        $personaRule = match ($persona) {
            'conservative' => 'Prioritize deadline safety and liquidity protection before speed.',
            'aggressive' => 'Prioritize completion speed but explicitly quantify execution risk.',
            default => 'Balance affordability and speed with consistent execution.',
        };

        $brutalRule = $brutalTruth
            ? "Include a section titled 'Brutal Financial Truth:' with direct downside statements and no softening language."
            : "Keep tone constructive and practical (not harsh).";

        $lazyRule = $stayLazyMode
            ? "Because this is a no-action scenario, include consequences and opportunity loss clearly."
            : "Assume active contributions from scenario values.";

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'top_p' => 0.95,
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
                        . "Executive Summary:\n"
                        . "- exactly 2 short lines with numbers.\n"
                        . "Critical Risks:\n"
                        . "- exactly 3 bullets, include deadline/conflict risks if present.\n"
                        . "Recommended Allocation:\n"
                        . "- exactly 3 bullets with explicit TND/month amounts and where to route them.\n"
                        . "Best Plan:\n"
                        . "- exactly 3 bullets, each with explicit TND/month actions.\n"
                        . "- include at least 1 spending-reduction move and 1 income-increase move with estimated TND/month impact.\n"
                        . "Next 30 Days:\n"
                        . "- exactly 2 bullets with concrete steps.\n"
                        . "Assessment:\n"
                        . "- 1 or 2 lines only, mandatory if affordability/risk gap is high.\n"
                        . "Brutal Financial Truth:\n"
                        . "- include only when brutal mode is on (max 2 bullets).\n"
                        . "If You Stay Lazy:\n"
                        . "- include only when lazy mode is inferred (max 2 bullets).\n\n"
                        . "Rules:\n"
                        . "- Do not invent missing data.\n"
                        . "- If net30d is weak, warn about affordability with concrete numbers.\n"
                        . "- Prioritize goals by priority/deadline.\n\n"
                        . "- Ensure recommendations are implementable next week.\n"
                        . "- Keep total response compact (~170-260 words).\n"
                        . "- {$personaRule}\n"
                        . "- {$brutalRule}\n"
                        . "- {$lazyRule}\n\n"
                        . "Language rule:\n"
                        . "- Match language style from context/user.\n\n"
                        . "Style variant for this run:\n"
                        . "- {$styleVariant}\n\n"
                        . "Scenario snapshot:\n"
                        . "- Persona: {$persona}\n"
                        . "- Monthly deposit: {$monthlyDeposit}\n"
                        . "- One-time deposit: {$oneTimeDeposit}\n"
                        . "- Brutal mode: " . ($brutalTruth ? 'on' : 'off') . "\n"
                        . "- Lazy mode inferred: " . ($stayLazyMode ? 'on' : 'off') . "\n\n"
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
