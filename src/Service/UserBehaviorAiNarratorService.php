<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class UserBehaviorAiNarratorService
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

    /**
     * @param array{
     *   score: int,
     *   profile_type: string,
     *   strengths: array<int, string>,
     *   weaknesses: array<int, string>,
     *   next_actions: array<int, string>,
     *   metrics: array<string, int|float>,
     *   week_tracking: array<string, int|float>,
     *   score_delta: int
     * } $context
     * @return array{ok: bool, source: string, text: string|null, model: string|null, error: string|null}
     */
    public function narrate(array $context): array
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

        $model = $this->env('OPENAI_MODEL', 'gpt-4o-mini');
        $endpoint = $this->env('OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions');

        $payload = [
            'model' => $model,
            'temperature' => 0.35,
            'max_tokens' => 260,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a senior personal finance coach. Keep advice concrete, concise, and numeric when possible.',
                ],
                [
                    'role' => 'user',
                    'content' => "Create a coaching note in French with exactly 3 sections:\n"
                        . "1) Diagnostic (2 short lines)\n"
                        . "2) Risque principal (1 short line)\n"
                        . "3) Plan 7 jours (3 bullet points)\n"
                        . "Use only this data:\n"
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
            return [
                'ok' => false,
                'source' => 'fallback',
                'text' => null,
                'model' => $model,
                'error' => (string) ($data['error']['message'] ?? ('OpenAI API HTTP ' . $status)),
            ];
        }

        $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
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

    /**
     * @param array{
     *   score: int,
     *   profile_type: string,
     *   strengths: array<int, string>,
     *   weaknesses: array<int, string>,
     *   next_actions: array<int, string>,
     *   metrics: array<string, int|float>,
     *   week_tracking: array<string, int|float>,
     *   score_delta: int
     * } $context
     */
    public function buildLocalFallback(array $context): string
    {
        $score = (int) ($context['score'] ?? 50);
        $profile = (string) ($context['profile_type'] ?? 'Insufficient Data');
        $delta = (int) ($context['score_delta'] ?? 0);
        $deltaText = $delta >= 0 ? '+' . $delta : (string) $delta;

        $riskLine = $score < 60
            ? "Risque principal: la discipline financière reste fragile à court terme."
            : "Risque principal: relâchement possible malgré une trajectoire globalement correcte.";

        $actions = $context['next_actions'] ?? [];
        $a1 = $actions[0] ?? 'Fixer un plafond hebdomadaire de dépenses et le suivre chaque dimanche.';
        $a2 = $actions[1] ?? 'Automatiser un virement d’épargne en début de mois.';
        $a3 = $actions[2] ?? 'Contrôler les 3 plus grosses dépenses de la semaine.';

        return "Diagnostic:\n"
            . "- Score actuel: {$score}/100 ({$profile}).\n"
            . "- Évolution hebdomadaire: {$deltaText} point(s).\n\n"
            . "{$riskLine}\n\n"
            . "Plan 7 jours:\n"
            . "- {$a1}\n"
            . "- {$a2}\n"
            . "- {$a3}";
    }
}

