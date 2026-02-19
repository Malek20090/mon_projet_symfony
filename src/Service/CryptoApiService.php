<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class CryptoApiService
{
    private HttpClientInterface $client;
    private CacheItemPoolInterface $cachePool;

    private const CACHE_TTL_SECONDS = 300;   // 5 min (fresh)
    private const STALE_TTL_SECONDS = 3600;  // 1 h (fallback)

    public function __construct(HttpClientInterface $client, CacheItemPoolInterface $cachePool)
    {
        $this->client = $client;
        $this->cachePool = $cachePool;
    }

    public function getPrices(array $cryptoApiIds): array
    {
        return $this->getPricesWithSource($cryptoApiIds)['prices'];
    }

    /**
     * @return array{prices: array<string, array{usd: float}>, source: string}
     */
    public function getPricesWithSource(array $cryptoApiIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strtolower', $cryptoApiIds))));
        sort($ids);
        if ($ids === []) {
            return ['prices' => [], 'source' => 'empty'];
        }

        $hash = md5(implode(',', $ids));
        $freshKey = 'crypto_prices_fresh_' . $hash;
        $staleKey = 'crypto_prices_stale_' . $hash;

        $freshItem = $this->cachePool->getItem($freshKey);
        if ($freshItem->isHit() && is_array($freshItem->get())) {
            return ['prices' => $freshItem->get(), 'source' => 'cache_fresh'];
        }

        try {
            $data = $this->fetchPrices($ids);

            $freshItem->set($data);
            $freshItem->expiresAfter(self::CACHE_TTL_SECONDS);
            $this->cachePool->save($freshItem);

            $staleItem = $this->cachePool->getItem($staleKey);
            $staleItem->set($data);
            $staleItem->expiresAfter(self::STALE_TTL_SECONDS);
            $this->cachePool->save($staleItem);

            return ['prices' => $data, 'source' => 'live'];
        } catch (\Throwable $e) {
            $staleItem = $this->cachePool->getItem($staleKey);
            if ($staleItem->isHit() && is_array($staleItem->get())) {
                return ['prices' => $staleItem->get(), 'source' => 'cache_stale'];
            }

            return ['prices' => [], 'source' => 'empty'];
        }
    }

    private function fetchPrices(array $ids): array
    {
        $response = $this->client->request('GET', 'https://api.coingecko.com/api/v3/simple/price', [
            'query' => [
                'ids' => implode(',', $ids),
                'vs_currencies' => 'usd',
            ],
            'timeout' => 8,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'DecideApp/1.0',
            ],
        ]);

        $raw = $response->toArray();
        $sanitized = [];

        foreach ($ids as $id) {
            if (isset($raw[$id]['usd']) && is_numeric($raw[$id]['usd'])) {
                $sanitized[$id] = ['usd' => (float) $raw[$id]['usd']];
            }
        }

        return $sanitized;
    }
}
