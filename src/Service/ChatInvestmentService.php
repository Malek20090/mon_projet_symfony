<?php

namespace App\Service;

use App\Entity\Investissement;
use App\Entity\User;
use App\Repository\CryptoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ChatInvestmentService
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly CryptoRepository $cryptoRepository,
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function handleMessage(string $message, User $user): array
    {
        $json = $this->parseWithGroq($message);
        if (!is_array($json) || !isset($json['action'])) {
            $json = $this->parseLocally($message);
        }

        if (($json['action'] ?? '') === 'invalid_request') {
            return [
                'success' => false,
                'error' => (string) ($json['message'] ?? 'Invalid request'),
            ];
        }

        if (!isset($json['crypto_symbol'], $json['amount'])) {
            return ['error' => 'Missing required fields'];
        }

        $crypto = $this->cryptoRepository->findOneBy([
            'symbol' => strtoupper((string) $json['crypto_symbol']),
        ]);
        if ($crypto === null) {
            return ['error' => 'Unsupported cryptocurrency'];
        }

        $amount = (float) $json['amount'];
        if ($amount <= 0) {
            return ['error' => 'Invalid amount'];
        }

        $buyPrice = (float) $crypto->getCurrentprice();
        if ($buyPrice <= 0) {
            return ['error' => 'Crypto price is not available'];
        }

        $investment = new Investissement();
        $investment->setAmountInvested($amount);
        $investment->setBuyPrice($buyPrice);
        $investment->setQuantity($amount / $buyPrice);
        $investment->setCreatedAt(new \DateTime());
        $investment->setCrypto($crypto);
        $investment->setUserId($user);

        $this->entityManager->persist($investment);
        $this->entityManager->flush();

        return [
            'success' => true,
            'crypto' => $crypto->getSymbol(),
            'amount' => $amount,
        ];
    }

    private function parseWithGroq(string $message): ?array
    {
        $apiKey = trim((string) ($_ENV['GROQ_API_KEY'] ?? ''));
        if ($apiKey === '') {
            return null;
        }

        try {
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
                            ['role' => 'system', 'content' => 'You are a financial assistant that ONLY returns strict JSON.'],
                            ['role' => 'user', 'content' => $this->buildPrompt($message)],
                        ],
                        'temperature' => 0,
                    ],
                ]
            );

            $data = $response->toArray(false);
            if (isset($data['error'])) {
                return null;
            }

            $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
            $text = preg_replace('/^```json|```$/', '', $text);
            $decoded = json_decode(trim((string) $text), true);

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseLocally(string $message): array
    {
        $input = mb_strtolower(trim($message));

        $symbolMap = [
            'bitcoin' => 'BTC', 'btc' => 'BTC',
            'ethereum' => 'ETH', 'eth' => 'ETH',
            'solana' => 'SOL', 'sol' => 'SOL',
            'cardano' => 'ADA', 'ada' => 'ADA',
            'ripple' => 'XRP', 'xrp' => 'XRP',
            'polkadot' => 'DOT', 'dot' => 'DOT',
            'dogecoin' => 'DOGE', 'doge' => 'DOGE',
        ];

        $foundSymbol = null;
        foreach ($symbolMap as $token => $symbol) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/i', $input) === 1) {
                $foundSymbol = $symbol;
                break;
            }
        }

        $amount = null;
        if (preg_match('/(\d+(?:[.,]\d+)?)/', $input, $m) === 1) {
            $amount = (float) str_replace(',', '.', $m[1]);
        }

        if ($foundSymbol === null || $amount === null || $amount <= 0) {
            return [
                'action' => 'invalid_request',
                'message' => 'You must specify both amount and supported cryptocurrency.',
            ];
        }

        return [
            'action' => 'create_investment',
            'crypto_symbol' => $foundSymbol,
            'amount' => $amount,
        ];
    }

    private function buildPrompt(string $userMessage): string
    {
        return "
You are a financial assistant.

Available cryptocurrencies (user may use FULL NAME or SYMBOL):
- Bitcoin / BTC
- Ethereum / ETH
- Solana / SOL
- Cardano / ADA
- Ripple / XRP
- Polkadot / DOT
- Dogecoin / DOGE

Rules:
1. Return ONLY valid JSON.
2. If both amount and supported cryptocurrency are provided, return:
{
  \"action\": \"create_investment\",
  \"crypto_symbol\": \"BTC\",
  \"amount\": 500
}
3. If amount OR crypto is missing or unsupported, return:
{
  \"action\": \"invalid_request\",
  \"message\": \"You must specify both amount and supported cryptocurrency.\"
}

User message:
\"$userMessage\"
";
    }
}

