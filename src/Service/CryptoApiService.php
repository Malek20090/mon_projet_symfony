<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CryptoApiService
{
    private HttpClientInterface $client;

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
    }

    public function getPrices(array $cryptoApiIds): array
    {
        $response = $this->client->request(
            'GET',
            'https://api.coingecko.com/api/v3/simple/price',
            [
                'query' => [
                    'ids' => implode(',', $cryptoApiIds),
                    'vs_currencies' => 'usd',
                ],
            ]
        );

        return $response->toArray();
    }
}
