<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BadWordsService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{has_bad_words: bool, matched: string[]}
     */
    public function analyze(string $text): array
    {
        $input = trim($text);
        if ($input === '') {
            return ['has_bad_words' => false, 'matched' => []];
        }

        $apiKey = $this->env('GROQ_API_KEY');
        if ($apiKey === '') {
            $this->logger->warning('BadWordsService: GROQ_API_KEY missing, moderation skipped.');
            return ['has_bad_words' => false, 'matched' => []];
        }

        $endpoint = $this->env('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
        $model = $this->env('GROQ_MODEL', 'llama-3.1-8b-instant');

        $systemPrompt = 'You are a strict moderation classifier for profanity and abusive insults. Return JSON only.';
        $userPrompt = "Analyze the following user text and detect profanity/insults.\n"
            . "Rules:\n"
            . "- has_bad_words: true only if explicit profanity, insults, or severe vulgarity are present.\n"
            . "- matched: list only explicit offending tokens found verbatim when possible.\n"
            . "- If clean or uncertain, set has_bad_words to false and matched to [].\n\n"
            . "TEXT:\n" . $input;

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $model,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ],
                'timeout' => 20,
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray(false);
            if ($status < 200 || $status >= 300) {
                $this->logger->warning('BadWordsService: moderation API HTTP error.', [
                    'status' => $status,
                    'error' => $data['error']['message'] ?? null,
                ]);
                return ['has_bad_words' => false, 'matched' => []];
            }

            $raw = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $this->logger->warning('BadWordsService: invalid moderation JSON.');
                return ['has_bad_words' => false, 'matched' => []];
            }

            $hasBadWords = (bool) ($decoded['has_bad_words'] ?? false);
            $matched = [];

            foreach ((array) ($decoded['matched'] ?? []) as $word) {
                if (!is_string($word)) {
                    continue;
                }
                $w = mb_strtolower(trim($word));
                if ($w !== '') {
                    $matched[] = $w;
                }
            }

            $matched = array_values(array_unique($matched));
            if ($matched !== []) {
                $hasBadWords = true;
            }

            return [
                'has_bad_words' => $hasBadWords,
                'matched' => $matched,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('BadWordsService: moderation request failed.', [
                'error' => $e->getMessage(),
            ]);

            return ['has_bad_words' => false, 'matched' => []];
        }
    }

    private function env(string $name, string $default = ''): string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if (!is_string($v) || trim($v) === '') {
            return $default;
        }

        return trim($v);
    }
}
