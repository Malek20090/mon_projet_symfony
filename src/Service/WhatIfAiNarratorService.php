<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WhatIfAiNarratorService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   ok:bool,
     *   source:string,
     *   advice:?array{
     *      executive_insight:string,
     *      why:string,
     *      best_action:array{title:string,details:string},
     *      alternatives:array<int,array{title:string,details:string}>,
     *      next_7_days:string
     *   },
     *   model:?string,
     *   error:?string
     * }
     */
    public function narrate(array $input, string $cacheKey): array
    {
        if (!$this->envBool('WHATIF_USE_OPENAI', true)) {
            return [
                'ok' => false,
                'source' => 'disabled',
                'advice' => null,
                'model' => null,
                'error' => 'WHATIF_USE_OPENAI is false.',
            ];
        }

        $apiKey = $this->env('OPENAI_API_KEY');
        if ($apiKey === '') {
            return [
                'ok' => false,
                'source' => 'fallback',
                'advice' => null,
                'model' => null,
                'error' => 'OPENAI_API_KEY is missing.',
            ];
        }

        $model = $this->env('WHATIF_OPENAI_MODEL', 'gpt-4o-mini');
        $endpoint = $this->env('WHATIF_OPENAI_ENDPOINT', 'https://api.openai.com/v1/chat/completions');
        $temperature = max(0.2, min(0.4, $this->envFloat('WHATIF_OPENAI_TEMPERATURE', 0.3)));

        try {
            $cached = $this->cache->get('whatif_ai_' . sha1($cacheKey), function (ItemInterface $item) use ($apiKey, $endpoint, $model, $temperature, $input): array {
                $item->expiresAfter(300);
                return $this->requestAdvice($apiKey, $endpoint, $model, $temperature, $input);
            });

            if (($cached['ok'] ?? false) !== true) {
                $this->logger->warning('What-if OpenAI narrator fallback', [
                    'error' => $cached['error'] ?? 'unknown error',
                    'model' => $model,
                ]);
            }

            return $cached;
        } catch (\Throwable $e) {
            $this->logger->error('What-if OpenAI narrator cache/request failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'source' => 'fallback',
                'advice' => null,
                'model' => $model,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $input
     * @return array{
     *   ok:bool,
     *   source:string,
     *   advice:?array{
     *      executive_insight:string,
     *      why:string,
     *      best_action:array{title:string,details:string},
     *      alternatives:array<int,array{title:string,details:string}>,
     *      next_7_days:string
     *   },
     *   model:?string,
     *   error:?string
     * }
     */
    private function requestAdvice(string $apiKey, string $endpoint, string $model, float $temperature, array $input): array
    {
        $systemPrompt = $this->systemPrompt();
        $userPrompt = $this->userPrompt($input);
        $schema = $this->jsonSchema();

        $payload = [
            'model' => $model,
            'temperature' => $temperature,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $schema,
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
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'source' => 'fallback',
                'advice' => null,
                'model' => $model,
                'error' => $e->getMessage(),
            ];
        }

        if ($status < 200 || $status >= 300) {
            return [
                'ok' => false,
                'source' => 'fallback',
                'advice' => null,
                'model' => $model,
                'error' => (string) ($data['error']['message'] ?? ('OpenAI API HTTP ' . $status)),
            ];
        }

        $raw = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        $parsed = $this->decodeAdvice($raw);
        if ($parsed !== null) {
            return [
                'ok' => true,
                'source' => 'openai',
                'advice' => $parsed,
                'model' => $model,
                'error' => null,
            ];
        }

        // Retry once with stricter correction instruction.
        $retryPayload = $payload;
        $retryPayload['messages'][] = [
            'role' => 'assistant',
            'content' => $raw === '' ? '[empty]' : $raw,
        ];
        $retryPayload['messages'][] = [
            'role' => 'user',
            'content' => 'Your previous answer was invalid JSON for the schema. Return only valid JSON matching the schema, no markdown, no extra keys.',
        ];

        try {
            $retryResponse = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $retryPayload,
                'timeout' => 20,
            ]);
            $retryStatus = $retryResponse->getStatusCode();
            $retryData = $retryResponse->toArray(false);
            if ($retryStatus >= 200 && $retryStatus < 300) {
                $retryRaw = trim((string) ($retryData['choices'][0]['message']['content'] ?? ''));
                $retryParsed = $this->decodeAdvice($retryRaw);
                if ($retryParsed !== null) {
                    return [
                        'ok' => true,
                        'source' => 'openai',
                        'advice' => $retryParsed,
                        'model' => $model,
                        'error' => null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->warning('What-if OpenAI retry failed', ['error' => $e->getMessage()]);
        }

        return [
            'ok' => false,
            'source' => 'fallback',
            'advice' => null,
            'model' => $model,
            'error' => 'Invalid AI JSON output.',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeAdvice(string $raw): ?array
    {
        if ($raw === '') {
            return null;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        $requiredTop = ['executive_insight', 'why', 'best_action', 'alternatives', 'next_7_days'];
        foreach ($requiredTop as $key) {
            if (!array_key_exists($key, $json)) {
                return null;
            }
        }

        if (!is_array($json['best_action']) || !is_array($json['alternatives'])) {
            return null;
        }

        $best = $json['best_action'];
        if (!isset($best['title'], $best['details']) || !is_string($best['title']) || !is_string($best['details'])) {
            return null;
        }

        $alts = array_values(array_filter($json['alternatives'], static fn(mixed $v): bool => is_array($v)));
        $normalizedAlts = [];
        foreach (array_slice($alts, 0, 2) as $alt) {
            $title = isset($alt['title']) && is_string($alt['title']) ? trim($alt['title']) : '';
            $details = isset($alt['details']) && is_string($alt['details']) ? trim($alt['details']) : '';
            if ($title !== '' && $details !== '') {
                $normalizedAlts[] = ['title' => $title, 'details' => $details];
            }
        }
        while (count($normalizedAlts) < 2) {
            $normalizedAlts[] = ['title' => 'Alternative', 'details' => 'No additional alternative available.'];
        }

        return [
            'executive_insight' => (string) $json['executive_insight'],
            'why' => (string) $json['why'],
            'best_action' => [
                'title' => (string) $best['title'],
                'details' => (string) $best['details'],
            ],
            'alternatives' => $normalizedAlts,
            'next_7_days' => (string) $json['next_7_days'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonSchema(): array
    {
        return [
            'name' => 'what_if_advice',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['executive_insight', 'why', 'best_action', 'alternatives', 'next_7_days'],
                'properties' => [
                    'executive_insight' => ['type' => 'string'],
                    'why' => ['type' => 'string'],
                    'best_action' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'details'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'details' => ['type' => 'string'],
                        ],
                    ],
                    'alternatives' => [
                        'type' => 'array',
                        'minItems' => 2,
                        'maxItems' => 2,
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['title', 'details'],
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'details' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'next_7_days' => ['type' => 'string'],
                ],
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return
            "You are Decide$ What-if AI Narrator for Savings & Goals.\n" .
            "You must output STRICT JSON only, matching the provided schema exactly.\n" .
            "Do not add markdown, prefaces, explanations, or extra keys.\n" .
            "Use only numbers from input JSON exactly as given. Do not invent or alter values.\n" .
            "Scope is only ONE savings goal. Never mention salary module, expense module, emergency module, or real-life cases.\n" .
            "Avoid generic advice like 'reduce spending' or 'increase income'.\n" .
            "Handle edge cases coherently:\n" .
            "- If current_monthly=0 and remaining_amount>0: no projected finish date.\n" .
            "- If remaining_amount<=0: goal already completed.\n" .
            "- If required_adjustment_safe<0: optimization mode; user can reduce monthly by abs(value) and stay safe.\n" .
            "Keep tone concise, professional, and metric-driven.";
    }

    /**
     * @param array<string,mixed> $input
     */
    private function userPrompt(array $input): string
    {
        return
            "Generate advisory narrative from this structured input JSON.\n" .
            "Constraints:\n" .
            "1) executive_insight max 2 lines.\n" .
            "2) why must reference at least 2 metrics.\n" .
            "3) best_action.details must include exact expected outcome (gap/confidence/finish).\n" .
            "4) alternatives exactly 2 options.\n" .
            "5) next_7_days is one concrete action sentence.\n" .
            "6) Never contradict metrics (example: confidence 100 cannot be described as uncertain).\n\n" .
            "Input JSON:\n" .
            json_encode($input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

    private function envBool(string $name, bool $default): bool
    {
        $raw = strtolower(trim($this->env($name, $default ? 'true' : 'false')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function envFloat(string $name, float $default): float
    {
        $raw = $this->env($name, (string) $default);
        return is_numeric($raw) ? (float) $raw : $default;
    }
}

