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

    /**
     * @return array{rate:float,year:?int,source:string,isFallback:bool,error:?string,series:array<int,float>}
     */
    public function fetchAnnualInflationSnapshot(string $countryCode = 'TN'): array
    {
        $countryCode = strtoupper(trim($countryCode));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $countryCode = 'TN';
        }

        // World Bank CPI inflation, annual % (indicator: FP.CPI.TOTL.ZG)
        $url = sprintf(
            'https://api.worldbank.org/v2/country/%s/indicator/FP.CPI.TOTL.ZG',
            rawurlencode($countryCode)
        );

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => [
                    'format' => 'json',
                    'per_page' => 80,
                ],
                'timeout' => 8,
            ]);
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            return [
                'rate' => 0.0,
                'year' => null,
                'source' => 'worldbank:FP.CPI.TOTL.ZG',
                'isFallback' => true,
                'error' => $e->getMessage(),
                'series' => [],
            ];
        }

        if (!isset($data[1]) || !is_array($data[1])) {
            return [
                'rate' => 0.0,
                'year' => null,
                'source' => 'worldbank:FP.CPI.TOTL.ZG',
                'isFallback' => true,
                'error' => 'Unexpected API shape',
                'series' => [],
            ];
        }

        $series = [];
        foreach ($data[1] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = $row['value'] ?? null; // percent value, e.g. 6.0
            if (!is_numeric($value)) {
                continue;
            }
            $yearRaw = $row['date'] ?? null;
            $year = is_numeric($yearRaw) ? (int) $yearRaw : null;
            if ($year === null || $year < 1960 || $year > 2100) {
                continue;
            }
            $rate = max(0.0, min(0.30, ((float) $value) / 100.0));
            $series[$year] = $rate;
        }

        if (count($series) > 0) {
            ksort($series);
            $latestYear = (int) max(array_keys($series));
            $latestRate = (float) ($series[$latestYear] ?? 0.0);
            return [
                'rate' => $latestRate,
                'year' => $latestYear,
                'source' => 'worldbank:FP.CPI.TOTL.ZG',
                'isFallback' => false,
                'error' => null,
                'series' => $series,
            ];
        }

        return [
            'rate' => 0.0,
            'year' => null,
            'source' => 'worldbank:FP.CPI.TOTL.ZG',
            'isFallback' => true,
            'error' => 'No usable CPI value',
            'series' => [],
        ];
    }

    public function fetchAnnualInflationRate(string $countryCode = 'TN'): float
    {
        $snapshot = $this->fetchAnnualInflationSnapshot($countryCode);
        return (float) ($snapshot['rate'] ?? 0.0);
    }

    /**
     * @param array<int,float> $historicalSeries year => annualRate
     * @return array<int,float> year => annualRate
     */
    public function buildProjectedInflationByYear(array $historicalSeries, int $fromYear, int $toYear): array
    {
        if ($fromYear > $toYear) {
            [$fromYear, $toYear] = [$toYear, $fromYear];
        }
        $fromYear = max(1960, $fromYear);
        $toYear = min(2100, $toYear);

        $clean = [];
        foreach ($historicalSeries as $y => $r) {
            $year = (int) $y;
            $rate = min(0.30, max(0.0, (float) $r));
            if ($year >= 1960 && $year <= 2100) {
                $clean[$year] = $rate;
            }
        }
        if (count($clean) === 0) {
            $out = [];
            for ($y = $fromYear; $y <= $toYear; $y++) {
                $out[$y] = 0.0;
            }
            return $out;
        }
        ksort($clean);
        $knownYears = array_keys($clean);
        $knownCount = count($knownYears);
        $latestYear = (int) $knownYears[$knownCount - 1];

        $slopes = [];
        for ($i = 1; $i < $knownCount; $i++) {
            $yPrev = (int) $knownYears[$i - 1];
            $yCur = (int) $knownYears[$i];
            $dt = max(1, $yCur - $yPrev);
            $dr = (float) $clean[$yCur] - (float) $clean[$yPrev];
            $slopes[] = $dr / $dt;
        }
        if (count($slopes) > 5) {
            $slopes = array_slice($slopes, -5);
        }
        $trend = count($slopes) > 0 ? array_sum($slopes) / count($slopes) : 0.0;
        $trend = min(0.03, max(-0.03, $trend));

        $out = [];
        for ($year = $fromYear; $year <= $toYear; $year++) {
            if (isset($clean[$year])) {
                $out[$year] = (float) $clean[$year];
                continue;
            }

            $prevYear = null;
            $nextYear = null;
            foreach ($knownYears as $ky) {
                if ($ky < $year) {
                    $prevYear = (int) $ky;
                    continue;
                }
                if ($ky > $year) {
                    $nextYear = (int) $ky;
                    break;
                }
            }

            if ($prevYear !== null && $nextYear !== null) {
                $span = max(1, $nextYear - $prevYear);
                $pos = ($year - $prevYear) / $span;
                $interp = (float) $clean[$prevYear] + ((float) $clean[$nextYear] - (float) $clean[$prevYear]) * $pos;
                $out[$year] = min(0.30, max(0.0, $interp));
                continue;
            }

            if ($prevYear !== null) {
                $yearsAhead = max(0, $year - $latestYear);
                $base = (float) ($clean[$latestYear] ?? 0.0);
                $projected = $base + ($trend * $yearsAhead);
                $out[$year] = min(0.30, max(0.0, $projected));
                continue;
            }

            if ($nextYear !== null) {
                $out[$year] = (float) $clean[$nextYear];
                continue;
            }

            $out[$year] = 0.0;
        }

        return $out;
    }

    /**
     * @param array<int,float> $inflationByYear
     * @return array<string,float> Map: Y-m-d => annualInflationRate
     */
    public function buildDailyInflationMapForMonthUsingYearMap(
        int $year,
        int $month,
        array $inflationByYear,
        float $defaultRate = 0.0
    ): array {
        $rate = (float) ($inflationByYear[$year] ?? $defaultRate);
        return $this->buildDailyInflationMapForMonth($year, $month, $rate);
    }

    /**
     * @return array<string, float> Map: Y-m-d => annualInflationRate (0..1)
     */
    public function buildDailyInflationMapForMonth(int $year, int $month, float $annualInflationRate): array
    {
        $annualInflationRate = min(0.30, max(0.0, $annualInflationRate));
        $days = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $out = [];
        for ($d = 1; $d <= $days; $d++) {
            $out[sprintf('%04d-%02d-%02d', $year, $month, $d)] = $annualInflationRate;
        }

        return $out;
    }

    public function adjustAmountForInflation(
        float $amount,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        float $annualInflationRate
    ): float {
        $amount = max(0.0, $amount);
        $annualInflationRate = min(0.30, max(0.0, $annualInflationRate));
        if ($amount <= 0.0 || $annualInflationRate <= 0.0 || $toDate <= $fromDate) {
            return $amount;
        }

        $days = (int) $fromDate->diff($toDate)->format('%a');
        $years = max(0.0, $days / 365.25);
        return $amount * pow(1 + $annualInflationRate, $years);
    }

    /**
     * @param array<int,float> $inflationByYear
     */
    public function adjustAmountForInflationByYear(
        float $amount,
        \DateTimeImmutable $fromDate,
        \DateTimeImmutable $toDate,
        array $inflationByYear,
        float $defaultRate = 0.0
    ): float {
        $amount = max(0.0, $amount);
        if ($amount <= 0.0 || $toDate <= $fromDate) {
            return $amount;
        }

        $startYear = (int) $fromDate->format('Y');
        $endYear = (int) $toDate->format('Y');
        $result = $amount;

        for ($y = $startYear; $y <= $endYear; $y++) {
            $segmentStart = $fromDate > new \DateTimeImmutable(sprintf('%04d-01-01', $y))
                ? $fromDate
                : new \DateTimeImmutable(sprintf('%04d-01-01', $y));
            $nextYearStart = new \DateTimeImmutable(sprintf('%04d-01-01', $y + 1));
            $segmentEnd = $toDate < $nextYearStart ? $toDate : $nextYearStart;
            if ($segmentEnd <= $segmentStart) {
                continue;
            }

            $days = (int) $segmentStart->diff($segmentEnd)->format('%a');
            $yearFraction = max(0.0, $days / 365.25);
            $rate = min(0.30, max(0.0, (float) ($inflationByYear[$y] ?? $defaultRate)));
            $result *= pow(1 + $rate, $yearFraction);
        }

        return $result;
    }
}
