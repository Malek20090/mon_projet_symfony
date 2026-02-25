<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;

class SavingsPdfService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function renderTransactionsPdf(
        array $rows,
        string $range,
        string $q,
        string $sort,
        \DateTimeInterface $generatedAt
    ): string {
        $html = $this->buildHtml($rows, $range, $q, $sort, $generatedAt);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildHtml(
        array $rows,
        string $range,
        string $q,
        string $sort,
        \DateTimeInterface $generatedAt
    ): string {
        $cssPath = dirname(__DIR__, 2) . '/public/css/savings-export.css';
        $baseCss = is_file($cssPath) ? (string) file_get_contents($cssPath) : '';

        $safeSearch = htmlspecialchars($q !== '' ? $q : '---', ENT_QUOTES, 'UTF-8');
        $safeRange = htmlspecialchars($range, ENT_QUOTES, 'UTF-8');
        $safeSort = htmlspecialchars($sort, ENT_QUOTES, 'UTF-8');
        $safeDate = htmlspecialchars($generatedAt->format('Y-m-d H:i'), ENT_QUOTES, 'UTF-8');

        $totalAmount = 0.0;
        foreach ($rows as $r) {
            $totalAmount += (float) ($r['montant'] ?? 0);
        }
        $safeTotal = number_format($totalAmount, 2, '.', ' ');
        $safeCount = (string) count($rows);
        $safeAvg = number_format(count($rows) > 0 ? ($totalAmount / count($rows)) : 0, 2, '.', ' ');

        $bodyRows = '';
        foreach ($rows as $i => $r) {
            $idx = $i + 1;
            $date = htmlspecialchars((string) ($r['date'] ?? ''), ENT_QUOTES, 'UTF-8');
            $type = htmlspecialchars((string) ($r['type'] ?? ''), ENT_QUOTES, 'UTF-8');
            $typeRaw = strtoupper((string) ($r['type'] ?? ''));
            $typeClass = match ($typeRaw) {
                'EPARGNE' => 'saving',
                'GOAL_CONTRIB' => 'contrib',
                'GOAL_REFUND' => 'refund',
                default => 'other',
            };
            $amount = number_format((float) ($r['montant'] ?? 0), 2, '.', ' ');
            $desc = htmlspecialchars((string) ($r['description'] ?? ''), ENT_QUOTES, 'UTF-8');

            $bodyRows .= "
                <tr>
                    <td>{$idx}</td>
                    <td>{$date}</td>
                    <td><span class='type-pill {$typeClass}'>{$type}</span></td>
                    <td class='amount right'>{$amount} TND</td>
                    <td>{$desc}</td>
                </tr>
            ";
        }

        if ($bodyRows === '') {
            $bodyRows = '<tr><td colspan="5">No transactions.</td></tr>';
        }

        return "
        <html>
        <head>
            <meta charset='utf-8'>
            <style>{$baseCss}</style>
        </head>
        <body>
            <div class='page'>
                <div class='header'>
                    <div class='brand'><span class='logo'>D$</span><span>Decide$</span></div>
                    <h1>Savings Transactions Report</h1>
                    <div class='meta'>
                        <span class='badge'>Generated: {$safeDate}</span>
                        <span class='badge'>Range: {$safeRange}</span>
                        <span class='badge'>Search: {$safeSearch}</span>
                        <span class='badge'>Sort: {$safeSort}</span>
                    </div>
                </div>

                <div class='card'>
                    <div class='summary-grid'>
                        <div class='summary-item'><div class='summary-k'>Transactions</div><div class='summary-v'>{$safeCount}</div></div>
                        <div class='summary-item'><div class='summary-k'>Total</div><div class='summary-v money'>{$safeTotal} TND</div></div>
                        <div class='summary-item'><div class='summary-k'>Average</div><div class='summary-v money'>{$safeAvg} TND</div></div>
                    </div>

                    <table>
                        <thead>
                            <tr><th>#</th><th>Date</th><th>Type</th><th class='right'>Amount</th><th>Description</th></tr>
                        </thead>
                        <tbody>
                            {$bodyRows}
                        </tbody>
                    </table>

                    <div class='footer'>
                        <span>Generated from Savings module export.</span>
                        <span>{$safeDate}</span>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
    }
}
