<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/goals', name: 'admin_goals_')]class AdminGoalsController extends AbstractController
{
    // ----------------------------
    // Helpers: user table / label
    // ----------------------------
    private function detectUserTable(Connection $conn): string
    {
        foreach (['user', 'users', 'app_user'] as $t) {
            $exists = $conn->fetchOne(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :t",
                ['t' => $t]
            );
            if ((int)$exists > 0) return $t;
        }
        return 'user';
    }

    private function detectUserLabelColumn(Connection $conn, string $userTable): string
    {
        foreach (['email', 'username', 'nom', 'name', 'full_name'] as $col) {
            $exists = $conn->fetchOne(
                "SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c",
                ['t' => $userTable, 'c' => $col]
            );
            if ((int)$exists > 0) return $col;
        }
        return 'id';
    }

    private function buildGoalStatus(float $current, float $target, ?string $deadline): string
    {
        if ($target > 0 && $current >= $target) return 'completed';
        if ($deadline) {
            $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
            if ($deadline < $today) return 'overdue';
        }
        return 'active';
    }

    // ----------------------------
    // Fetch GOALS
    // ----------------------------
    private function fetchGoals(Connection $conn, Request $request): array
    {
        $userTable = $this->detectUserTable($conn);
        $userLabelCol = $this->detectUserLabelColumn($conn, $userTable);

        $q        = trim((string)$request->query->get('g_q', ''));
        $priority = trim((string)$request->query->get('g_priority', ''));
        $status   = trim((string)$request->query->get('g_status', ''));
        $from     = trim((string)$request->query->get('g_from', ''));
        $to       = trim((string)$request->query->get('g_to', ''));

        $sort = (string)$request->query->get('g_sort', 'deadline');
        $dir  = strtoupper((string)$request->query->get('g_dir', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $sortMap = [
            'user'     => 'u_label',
            'goal'     => 'g.nom',
            'target'   => 'g.montant_cible',
            'current'  => 'g.montant_actuel',
            'deadline' => 'g.date_limite',
            'priority' => 'g.priorite',
        ];
        $orderBy = $sortMap[$sort] ?? 'g.date_limite';

        $where = [];
        $params = [];

        if ($q !== '') {
            if ($userLabelCol === 'id') {
                $where[] = "u.id = :uid";
                $params['uid'] = (int)$q;
            } else {
                $where[] = "u.`$userLabelCol` LIKE :q";
                $params['q'] = '%' . $q . '%';
            }
        }
        if ($priority !== '') {
            $where[] = "g.priorite = :p";
            $params['p'] = (int)$priority;
        }
        if ($from !== '') {
            $where[] = "g.date_limite >= :from";
            $params['from'] = $from;
        }
        if ($to !== '') {
            $where[] = "g.date_limite <= :to";
            $params['to'] = $to;
        }

        $sql = "
            SELECT
                g.id, g.nom, g.montant_cible, g.montant_actuel, g.date_limite, g.priorite,
                sa.id AS saving_account_id,
                sa.user_id,
                u.id AS user_id2,
                u.`$userLabelCol` AS u_label
            FROM financial_goal g
            INNER JOIN saving_account sa ON sa.id = g.saving_account_id
            INNER JOIN `$userTable` u ON u.id = sa.user_id
        ";

        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY $orderBy $dir";

        $rows = $conn->fetchAllAssociative($sql, $params);

        foreach ($rows as &$r) {
            $target = (float)$r['montant_cible'];
            $current = (float)$r['montant_actuel'];
            $deadline = $r['date_limite'] ?? null;

            $r['status'] = $this->buildGoalStatus($current, $target, $deadline);
            $r['progress'] = ($target > 0) ? min(100, max(0, ($current / $target) * 100)) : 0;
        }
        unset($r);

        if ($status !== '') {
            $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $status));
        }

        return $rows;
    }

    // ----------------------------
    // Fetch SAVING ACCOUNTS
    // ----------------------------
    private function fetchSavingAccounts(Connection $conn, Request $request): array
    {
        $userTable = $this->detectUserTable($conn);
        $userLabelCol = $this->detectUserLabelColumn($conn, $userTable);

        $q     = trim((string)$request->query->get('sa_q', ''));
        $from  = trim((string)$request->query->get('sa_from', ''));
        $to    = trim((string)$request->query->get('sa_to', ''));

        // optional: min/max balance filters
        $minSold = trim((string)$request->query->get('sa_min', ''));
        $maxSold = trim((string)$request->query->get('sa_max', ''));

        $sort = (string)$request->query->get('sa_sort', 'date');
        $dir  = strtoupper((string)$request->query->get('sa_dir', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $sortMap = [
            'user' => 'u_label',
            'sold' => 'sa.sold',
            'rate' => 'sa.taux_interet',
            'date' => 'sa.date_creation',
            'id'   => 'sa.id',
        ];
        $orderBy = $sortMap[$sort] ?? 'sa.date_creation';

        $where = [];
        $params = [];

        if ($q !== '') {
            if ($userLabelCol === 'id') {
                $where[] = "u.id = :uid";
                $params['uid'] = (int)$q;
            } else {
                $where[] = "u.`$userLabelCol` LIKE :q";
                $params['q'] = '%' . $q . '%';
            }
        }

        if ($from !== '') {
            $where[] = "sa.date_creation >= :from";
            $params['from'] = $from;
        }
        if ($to !== '') {
            $where[] = "sa.date_creation <= :to";
            $params['to'] = $to;
        }

        if ($minSold !== '') {
            $where[] = "sa.sold >= :minSold";
            $params['minSold'] = (float)$minSold;
        }
        if ($maxSold !== '') {
            $where[] = "sa.sold <= :maxSold";
            $params['maxSold'] = (float)$maxSold;
        }

        $sql = "
            SELECT
                sa.id, sa.sold, sa.date_creation, sa.taux_interet, sa.user_id,
                u.`$userLabelCol` AS u_label
            FROM saving_account sa
            INNER JOIN `$userTable` u ON u.id = sa.user_id
        ";

        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " ORDER BY $orderBy $dir";

        return $conn->fetchAllAssociative($sql, $params);
    }

    // ----------------------------
    // PAGE: goals + saving accounts
    // ----------------------------
    #[Route('', name: 'admin_goals_index', methods: ['GET'])]
    public function index(Connection $conn, Request $request): Response
    {
        $goals = $this->fetchGoals($conn, $request);
        $savingAccounts = $this->fetchSavingAccounts($conn, $request);

        $goalStats = [
            'total' => count($goals),
            'active' => count(array_filter($goals, fn($r) => $r['status'] === 'active')),
            'overdue' => count(array_filter($goals, fn($r) => $r['status'] === 'overdue')),
            'completed' => count(array_filter($goals, fn($r) => $r['status'] === 'completed')),
        ];

        $saStats = [
            'total' => count($savingAccounts),
            'sum_sold' => array_sum(array_map(fn($r) => (float)$r['sold'], $savingAccounts)),
            'avg_rate' => (count($savingAccounts) > 0)
                ? array_sum(array_map(fn($r) => (float)$r['taux_interet'], $savingAccounts)) / count($savingAccounts)
                : 0,
        ];

        return $this->render('goals/index.html.twig', [
    'goals' => $goals,
    'savingAccounts' => $savingAccounts,

    // ✅ pour que {{ stats.* }} marche
    'stats' => $goalStats,

    // ✅ stats saving accounts
    'saStats' => $saStats,
]);

    }

    // ----------------------------
    // EXPORT GOALS CSV/PDF
    // ----------------------------
    #[Route('/export/goals/csv', name: 'admin_goals_export_goals_csv', methods: ['GET'])]
    public function exportGoalsCsv(Connection $conn, Request $request): Response
    {
        $rows = $this->fetchGoals($conn, $request);

        $response = new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'User', 'Goal', 'Target', 'Current', 'Progress%', 'Deadline', 'Priority', 'Status']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'], $r['u_label'], $r['nom'], $r['montant_cible'], $r['montant_actuel'],
                    round((float)$r['progress'], 2), $r['date_limite'], $r['priorite'], $r['status'],
                ]);
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="goals.csv"');
        return $response;
    }

    #[Route('/export/goals/pdf', name: 'admin_goals_export_goals_pdf', methods: ['GET'])]
    public function exportGoalsPdf(Connection $conn, Request $request): Response
    {
        $rows = $this->fetchGoals($conn, $request);

        $html = $this->renderView('goals/pdf_goals.html.twig', [
            'rows' => $rows,
            'generatedAt' => new \DateTimeImmutable(),
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="goals.pdf"',
        ]);
    }

    // ----------------------------
    // EXPORT SAVING ACCOUNTS CSV/PDF
    // ----------------------------
    #[Route('/export/saving/csv', name: 'admin_goals_export_saving_csv', methods: ['GET'])]
    public function exportSavingCsv(Connection $conn, Request $request): Response
    {
        $rows = $this->fetchSavingAccounts($conn, $request);

        $response = new StreamedResponse(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['#', 'User', 'Sold', 'Interest rate', 'Created']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'], $r['u_label'], $r['sold'], $r['taux_interet'], $r['date_creation'],
                ]);
            }
            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="saving_accounts.csv"');
        return $response;
    }

    #[Route('/export/saving/pdf', name: 'admin_goals_export_saving_pdf', methods: ['GET'])]
    public function exportSavingPdf(Connection $conn, Request $request): Response
    {
        $rows = $this->fetchSavingAccounts($conn, $request);

        $html = $this->renderView('goals/pdf_saving.html.twig', [
            'rows' => $rows,
            'generatedAt' => new \DateTimeImmutable(),
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="saving_accounts.pdf"',
        ]);
    }
}
