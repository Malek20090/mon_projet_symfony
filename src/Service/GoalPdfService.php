<?php

namespace App\Service;

use App\Entity\FinancialGoal;
use Dompdf\Dompdf;
use Dompdf\Options;

class GoalPdfService
{
    /**
     * @param FinancialGoal[] $goals
     */
    public function renderGoalsPdf(array $goals): string
    {
        $html = $this->buildHtml($goals);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildHtml(array $goals): string
    {
        $rows = '';
        foreach ($goals as $g) {
            $target = number_format((float)$g->getMontantCible(), 2);
            $current = number_format((float)$g->getMontantActuel(), 2);
            $prio = (int)($g->getPriorite() ?? 0);
            $deadline = $g->getDateLimite()?->format('Y-m-d') ?? '-';
            $pct = ($g->getMontantCible() > 0) ? min(100, (($g->getMontantActuel() / $g->getMontantCible()) * 100)) : 0;
            $pct = (int)round($pct);

            $rows .= "
              <tr>
                <td>{$g->getId()}</td>
                <td>".htmlspecialchars($g->getNom())."</td>
                <td>{$target}</td>
                <td>{$current}</td>
                <td>{$pct}%</td>
                <td>{$deadline}</td>
                <td>P{$prio}</td>
              </tr>
            ";
        }

        return "
        <html><head><meta charset='utf-8'>
        <style>
          body{ font-family: DejaVu Sans, sans-serif; font-size:12px; }
          h1{ font-size:18px; margin:0 0 10px; }
          table{ width:100%; border-collapse:collapse; }
          th,td{ border:1px solid #ddd; padding:8px; text-align:left; }
          th{ background:#f3f6f9; }
        </style>
        </head><body>
          <h1>Decide$ — Goals Report</h1>
          <div>Generated: ".date('Y-m-d H:i')."</div>
          <br>
          <table>
            <thead>
              <tr>
                <th>#</th><th>Name</th><th>Target</th><th>Current</th><th>Progress</th><th>Deadline</th><th>Priority</th>
              </tr>
            </thead>
            <tbody>{$rows}</tbody>
          </table>
        </body></html>
        ";
    }
}
