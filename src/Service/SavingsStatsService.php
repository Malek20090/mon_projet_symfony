<?php

namespace App\Service;

class SavingsStatsService
{
    /**
     * @param array<int, array<string, mixed>> $transactions
     * @return array{
     *   stat_by: string,
     *   tx_stats: array{total:int,sum:float,avg:float,max:float},
     *   stat_labels: array<int, string>,
     *   stat_values: array<int, float>
     * }
     */
    public function build(array $transactions, string $statBy): array
    {
        $allowedStatBy = ['type', 'day', 'month', 'amount_bucket', 'description'];
        if (!in_array($statBy, $allowedStatBy, true)) {
            $statBy = 'type';
        }

        $txStats = [
            'total' => count($transactions),
            'sum' => 0.0,
            'avg' => 0.0,
            'max' => 0.0,
        ];

        if (!empty($transactions)) {
            $sum = 0.0;
            $max = 0.0;

            foreach ($transactions as $t) {
                $m = (float) ($t['montant'] ?? 0);
                $sum += $m;
                if ($m > $max) {
                    $max = $m;
                }
            }

            $txStats['sum'] = $sum;
            $txStats['max'] = $max;
            $txStats['avg'] = $sum / max(1, count($transactions));
        }

        $bucket = static function (float $m): string {
            if ($m < 100) return '< 100';
            if ($m < 500) return '100 - 499';
            if ($m < 1000) return '500 - 999';
            if ($m < 5000) return '1000 - 4999';
            if ($m < 10000) return '5000 - 9999';
            return '>= 10000';
        };

        $statMap = [];
        foreach ($transactions as $t) {
            $type = (string) ($t['type'] ?? '');
            $desc = (string) ($t['description'] ?? '');
            $m = (float) ($t['montant'] ?? 0);
            $dtRaw = $t['date'] ?? '';
            if ($dtRaw instanceof \DateTimeInterface) {
                $dt = $dtRaw->format('Y-m-d');
            } else {
                $dt = (string) $dtRaw;
            }

            $dayKey = $dt ? substr($dt, 0, 10) : 'Unknown';
            $monthKey = $dt ? substr($dt, 0, 7) : 'Unknown';

            if ($statBy === 'type') {
                $key = $type !== '' ? $type : 'Unknown';
            } elseif ($statBy === 'day') {
                $key = $dayKey;
            } elseif ($statBy === 'month') {
                $key = $monthKey;
            } elseif ($statBy === 'amount_bucket') {
                $key = $bucket($m);
            } else {
                $key = trim($desc) !== '' ? trim($desc) : 'No description';
            }

            if (!isset($statMap[$key])) {
                $statMap[$key] = 0.0;
            }
            $statMap[$key] += $m;
        }

        ksort($statMap);

        return [
            'stat_by' => $statBy,
            'tx_stats' => $txStats,
            'stat_labels' => array_keys($statMap),
            'stat_values' => array_values($statMap),
        ];
    }
}
