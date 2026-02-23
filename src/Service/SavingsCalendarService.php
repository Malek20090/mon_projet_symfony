<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SavingsCalendarService
{
    public function __construct(private readonly HttpClientInterface $httpClient)
    {
    }

    /**
     * @return array<int, array{date:string,name:string,localName:string}>
     */
    public function fetchPublicHolidays(int $year, string $countryCode = 'TN'): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $countryCode = 'TN';
        }

        $url = sprintf('https://date.nager.at/api/v3/PublicHolidays/%d/%s', $year, $countryCode);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 5,
            ]);
            $rows = $response->toArray(false);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $date = (string) ($row['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            $items[] = [
                'date' => $date,
                'name' => (string) ($row['name'] ?? 'Holiday'),
                'localName' => (string) ($row['localName'] ?? ($row['name'] ?? 'Holiday')),
            ];
        }

        return $items;
    }

    /**
     * @return array<string, float> Map: Y-m-d => riskScore(0..1)
     */
    public function fetchWeatherRiskMapForMonth(
        int $year,
        int $month,
        float $latitude = 36.8065,
        float $longitude = 10.1815
    ): array {
        $url = 'https://api.open-meteo.com/v1/forecast';
        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'daily' => 'weathercode,temperature_2m_max',
                    'timezone' => 'auto',
                    'forecast_days' => 16,
                ],
                'timeout' => 5,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return [];
        }

        $daily = is_array($data['daily'] ?? null) ? $data['daily'] : [];
        $dates = is_array($daily['time'] ?? null) ? $daily['time'] : [];
        $codes = is_array($daily['weathercode'] ?? null) ? $daily['weathercode'] : [];
        $temps = is_array($daily['temperature_2m_max'] ?? null) ? $daily['temperature_2m_max'] : [];

        $out = [];
        $count = min(count($dates), count($codes), count($temps));
        for ($i = 0; $i < $count; $i++) {
            $date = (string) $dates[$i];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }

            if ((int) substr($date, 0, 4) !== $year || (int) substr($date, 5, 2) !== $month) {
                continue;
            }

            $code = (int) $codes[$i];
            $temp = (float) $temps[$i];
            $risk = 0.15;

            if (in_array($code, [95, 96, 99], true)) {
                $risk = 1.0;
            } elseif (in_array($code, [65, 67, 75, 77, 82, 86], true)) {
                $risk = 0.8;
            } elseif (in_array($code, [61, 63, 66, 80, 81], true)) {
                $risk = 0.6;
            } elseif (in_array($code, [45, 48, 51, 53, 55, 56, 57], true)) {
                $risk = 0.4;
            }

            if ($temp >= 40.0) {
                $risk += 0.4;
            } elseif ($temp >= 35.0) {
                $risk += 0.2;
            }

            $out[$date] = min(1.0, max(0.0, $risk));
        }

        return $out;
    }

    /**
     * @return array<string, float> Map: Y-m-d => FX(fromCurrency -> TND)
     */
    public function fetchExchangeRateMapToTndForMonth(
        string $fromCurrency,
        int $year,
        int $month
    ): array {
        $fromCurrency = strtoupper(trim($fromCurrency));
        if (!preg_match('/^[A-Z]{3}$/', $fromCurrency)) {
            $fromCurrency = 'TND';
        }
        if ($fromCurrency === 'TND') {
            $out = [];
            $days = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
            for ($d = 1; $d <= $days; $d++) {
                $out[sprintf('%04d-%02d-%02d', $year, $month, $d)] = 1.0;
            }
            return $out;
        }

        $result = [];
        $daysInMonth = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

        // Historical daily snapshots source (no key): fawazahmed0/currency-api
        // It provides date-based JSON, useful for day-by-day "ups and downs".
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dateKey = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $url = sprintf(
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@%s/v1/currencies/%s.json',
                $dateKey,
                strtolower($fromCurrency)
            );

            try {
                $response = $this->httpClient->request('GET', $url, ['timeout' => 6]);
                $data = $response->toArray(false);
            } catch (\Throwable $e) {
                continue;
            }

            $base = strtolower($fromCurrency);
            $rate = (float) ($data[$base]['tnd'] ?? 0);
            if ($rate > 0) {
                $result[$dateKey] = $rate;
            }
        }

        return $result;
    }

    public function fetchExchangeRateToTnd(string $fromCurrency = 'TND'): float
    {
        $fromCurrency = strtoupper(trim($fromCurrency));
        if (!preg_match('/^[A-Z]{3}$/', $fromCurrency)) {
            $fromCurrency = 'TND';
        }
        if ($fromCurrency === 'TND') {
            return 1.0;
        }

        // First try daily-snapshot source for today's date.
        $todayMap = $this->fetchExchangeRateMapToTndForMonth(
            $fromCurrency,
            (int) date('Y'),
            (int) date('m')
        );
        if (!empty($todayMap)) {
            $todayKey = date('Y-m-d');
            if (isset($todayMap[$todayKey]) && $todayMap[$todayKey] > 0) {
                return (float) $todayMap[$todayKey];
            }
            $last = end($todayMap);
            if ($last !== false && (float) $last > 0) {
                return (float) $last;
            }
        }

        // Fallback source (latest only): frankfurter.app
        $url = 'https://api.frankfurter.app/latest';
        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'from' => $fromCurrency,
                    'to' => 'TND',
                ],
                'timeout' => 5,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return 0.0;
        }

        $rate = (float) ($data['rates']['TND'] ?? 0);
        return $rate > 0 ? $rate : 0.0;
    }
}
