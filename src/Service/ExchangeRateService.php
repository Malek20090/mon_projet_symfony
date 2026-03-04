<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class ExchangeRateService
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    public function getRate(string $fromCurrency, string $toCurrency): ?float
    {
        $from = strtoupper(trim($fromCurrency));
        $to = strtoupper(trim($toCurrency));

        if ($from === '' || $to === '') {
            return null;
        }

        if ($from === $to) {
            return 1.0;
        }

        try {
            $response = $this->httpClient->request('GET', 'https://api.frankfurter.app/latest', [
                'query' => [
                    'from' => $from,
                    'to' => $to,
                ],
                'timeout' => 10,
            ]);
            $data = $response->toArray(false);
            $rate = $data['rates'][$to] ?? null;
        } catch (\Throwable) {
            $rate = null;
        }

        if (is_numeric($rate) && (float) $rate > 0) {
            return (float) $rate;
        }

        // Fallback source: fawazahmed0/currency-api (no key), date snapshots.
        $fallbackRate = $this->fetchDailySnapshotRate($from, $to, new \DateTimeImmutable('today'));
        if ($fallbackRate !== null && $fallbackRate > 0) {
            return $fallbackRate;
        }

        // Second fallback: walk backward in current month for latest available snapshot.
        $today = new \DateTimeImmutable('today');
        $daysInMonth = (int) $today->format('t');
        $year = (int) $today->format('Y');
        $month = (int) $today->format('m');
        for ($day = $daysInMonth; $day >= 1; $day--) {
            $date = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
            $fallbackRate = $this->fetchDailySnapshotRate($from, $to, $date);
            if ($fallbackRate !== null && $fallbackRate > 0) {
                return $fallbackRate;
            }
        }

        return null;
    }

    private function fetchDailySnapshotRate(string $from, string $to, \DateTimeImmutable $date): ?float
    {
        $url = sprintf(
            'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@%s/v1/currencies/%s.json',
            $date->format('Y-m-d'),
            strtolower($from)
        );

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 8]);
            $data = $response->toArray(false);
            $base = strtolower($from);
            $target = strtolower($to);
            $rate = $data[$base][$target] ?? null;

            return is_numeric($rate) ? (float) $rate : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
