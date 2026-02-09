<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Security;

class SavingsController extends AbstractController
{
    // ----------------------------
    // Helpers
    // ----------------------------

    private function safeFloat(mixed $v): float
    {
        if ($v === null || $v === '') return 0.0;
        return (float) str_replace(',', '.', (string) $v);
    }

    private function safeInt(mixed $v, int $default = 0): int
    {
        if ($v === null || $v === '') return $default;
        return (int) $v;
    }

    private function clampSort(string $sort, array $allowed, string $default): string
    {
        return in_array($sort, $allowed, true) ? $sort : $default;
    }

    private function detectUserTable(Connection $conn): string
    {
        foreach (['user', 'users'] as $t) {
            try {
                $conn->fetchOne("SELECT 1 FROM `$t` LIMIT 1");
                return $t;
            } catch (\Throwable $e) {}
        }
        return 'user';
    }

    private function pkColumn(Connection $conn, string $table): string
    {
        $rows = $conn->fetchAllAssociative("SHOW COLUMNS FROM `$table`");
        foreach ($rows as $r) {
            if (($r['Key'] ?? '') === 'PRI') {
                return (string) $r['Field'];
            }
        }
        $cols = array_map(fn($r) => strtolower((string) $r['Field']), $rows);
        if (in_array('id', $cols, true)) return 'id';

        throw new \RuntimeException("No primary key column detected in `$table`.");
    }

    private function userFkColumn(Connection $conn, string $table): string
    {
        $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `$table`");
        $colsLower = array_map('strtolower', $cols);

        $candidates = ['user_id', 'id_user', 'utilisateur_id', 'id_utilisateur', 'users_id', 'userid'];

        foreach ($candidates as $cand) {
            if (in_array(strtolower($cand), $colsLower, true)) {
                foreach ($cols as $real) {
                    if (strtolower($real) === strtolower($cand)) return $real;
                }
                return $cand;
            }
        }

        throw new \RuntimeException("No user FK column found in table `$table`. Columns: " . implode(', ', $cols));
    }

    private function savingBalanceColumn(Connection $conn): string
    {
        $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `saving_account`");
        $colsLower = array_map('strtolower', $cols);

        $candidates = ['sold', 'solde', 'balance', 'montant'];
        foreach ($candidates as $cand) {
            if (in_array(strtolower($cand), $colsLower, true)) {
                foreach ($cols as $real) {
                    if (strtolower($real) === strtolower($cand)) return $real;
                }
                return $cand;
            }
        }

        throw new \RuntimeException("No balance column found in `saving_account`. Columns: " . implode(', ', $cols));
    }

    private function goalAccountFkColumn(Connection $conn): string
    {
        $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `financial_goal`");
        $colsLower = array_map('strtolower', $cols);

        $candidates = ['saving_account_id','savingaccount_id','account_id','saving_account'];

        foreach ($candidates as $cand) {
            if (in_array(strtolower($cand), $colsLower, true)) {
                foreach ($cols as $real) {
                    if (strtolower($real) === strtolower($cand)) return $real;
                }
                return $cand;
            }
        }

        throw new \RuntimeException("No saving_account FK column found in `financial_goal`. Columns: " . implode(', ', $cols));
    }

    private function resolveUserId(Connection $conn, Security $security, Request $request): int
    {
        $symUser = $security->getUser();

        if ($symUser && method_exists($symUser, 'getId') && $symUser->getId()) {
            return (int) $symUser->getId();
        }

        $userTable = $this->detectUserTable($conn);

        if ($symUser && method_exists($symUser, 'getUserIdentifier')) {
            $email = $symUser->getUserIdentifier();
            if ($email) {
                // try id (common)
                try {
                    $id = $conn->fetchOne("SELECT id FROM `$userTable` WHERE email = :email LIMIT 1", [
                        'email' => $email
                    ]);
                    if ($id) return (int) $id;
                } catch (\Throwable $e) {}

                // try pk detected (safer)
                try {
                    $pk = $this->pkColumn($conn, $userTable);
                    $id = $conn->fetchOne("SELECT `$pk` FROM `$userTable` WHERE email = :email LIMIT 1", [
                        'email' => $email
                    ]);
                    if ($id) return (int) $id;
                } catch (\Throwable $e) {}
            }
        }

        $manual = (int) $request->query->get('user_id', 0);
        if ($manual > 0) return $manual;

        // fallback: first user
        try {
            $pk = $this->pkColumn($conn, $userTable);
            $fallback = $conn->fetchOne("SELECT `$pk` FROM `$userTable` ORDER BY `$pk` ASC LIMIT 1");
            return $fallback ? (int) $fallback : 1;
        } catch (\Throwable $e) {
            $fallback = $conn->fetchOne("SELECT id FROM `$userTable` ORDER BY id ASC LIMIT 1");
            return $fallback ? (int) $fallback : 1;
        }
    }

    private function getOrCreateCurrentAccount(Connection $conn, int $userId): array
    {
        $accUserCol     = $this->userFkColumn($conn, 'saving_account');
        $accBalanceCol  = $this->savingBalanceColumn($conn);
        $accPkCol       = $this->pkColumn($conn, 'saving_account');

        $accounts = $conn->fetchAllAssociative(
            "SELECT * FROM saving_account WHERE `$accUserCol` = :uid ORDER BY `$accPkCol` DESC",
            ['uid' => $userId]
        );
        $currentAccount = $accounts[0] ?? null;

        if (!$currentAccount) {
            $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `saving_account`");
            $colsLower = array_map('strtolower', $cols);

            $data = [];
            $data[$accUserCol] = $userId;
            $data[$accBalanceCol] = 0;

            if (in_array('taux_interet', $colsLower, true)) $data['taux_interet'] = 0;
            if (in_array('date_creation', $colsLower, true)) $data['date_creation'] = date('Y-m-d');

            $conn->insert('saving_account', $data);

            $accounts = $conn->fetchAllAssociative(
                "SELECT * FROM saving_account WHERE `$accUserCol` = :uid ORDER BY `$accPkCol` DESC",
                ['uid' => $userId]
            );
            $currentAccount = $accounts[0] ?? null;
        }

        return [
            'accUserCol' => $accUserCol,
            'accBalanceCol' => $accBalanceCol,
            'accPkCol' => $accPkCol,
            'accounts' => $accounts,
            'currentAccount' => $currentAccount ?? [],
            'accId' => (int) (($currentAccount[$accPkCol] ?? 0)),
        ];
    }

    // -------------------------------------------------
    // Shared actions (used by index() and routes)
    // -------------------------------------------------

    private function doDeposit(Connection $conn, int $userId, int $accId, string $accUserCol, string $accBalanceCol, string $accPkCol, float $amount, string $desc): void
    {
        if ($amount <= 0 || $accId <= 0) return;

        $conn->beginTransaction();
        try {
            // 1) Update balance
            $conn->executeStatement(
                "UPDATE saving_account
                 SET `$accBalanceCol` = `$accBalanceCol` + :a
                 WHERE `$accPkCol` = :id AND `$accUserCol` = :uid",
                ['a' => $amount, 'id' => $accId, 'uid' => $userId]
            );

            // 2) Insert history row in YOUR existing table: transaction
            $conn->insert('transaction', [
                'type' => 'EPARGNE',
                'montant' => $amount,
                'date' => (new \DateTime())->format('Y-m-d H:i:s'),
                'description' => ($desc !== '' ? $desc : 'Deposit'),
                'module_source' => 'SAVINGS',
                'user_id' => $userId,
            ]);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
        }
    }

    private function doAddGoal(Connection $conn, int $accId, string $goalAccCol, string $nom, float $target, float $current, string $dateLimite, int $priorite): void
    {
        if ($nom === '' || $target <= 0 || $accId <= 0) return;

        $conn->insert('financial_goal', [
            'nom' => $nom,
            'montant_cible' => $target,
            'montant_actuel' => max(0, $current),
            'date_limite' => $dateLimite !== '' ? $dateLimite : null,
            'priorite' => min(5, max(1, $priorite)),
            $goalAccCol => $accId,
        ]);
    }

    private function doContributeGoal(Connection $conn, int $userId, int $accId, string $goalAccCol, string $accUserCol, string $accBalanceCol, string $accPkCol, int $goalId, float $add): void
    {
        if ($goalId <= 0 || $add <= 0 || $accId <= 0) return;

        $goal = $conn->fetchAssociative(
            "SELECT id FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
            ['gid' => $goalId, 'acc' => $accId]
        );
        if (!$goal) return;

        $balance = (float) $conn->fetchOne(
            "SELECT `$accBalanceCol` FROM saving_account
             WHERE `$accPkCol` = :id AND `$accUserCol` = :uid LIMIT 1",
            ['id' => $accId, 'uid' => $userId]
        );

        if ($balance < $add) return;

        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                "UPDATE saving_account
                 SET `$accBalanceCol` = `$accBalanceCol` - :a
                 WHERE `$accPkCol` = :id AND `$accUserCol` = :uid",
                ['a' => $add, 'id' => $accId, 'uid' => $userId]
            );

            $conn->executeStatement(
                "UPDATE financial_goal
                 SET montant_actuel = montant_actuel + :a
                 WHERE id = :gid AND `$goalAccCol` = :acc",
                ['a' => $add, 'gid' => $goalId, 'acc' => $accId]
            );

            // optional history:
            try {
                $conn->insert('transaction', [
                    'type' => 'GOAL_CONTRIB',
                    'montant' => $add,
                    'date' => (new \DateTime())->format('Y-m-d H:i:s'),
                    'description' => 'Contribution to goal #' . $goalId,
                    'module_source' => 'SAVINGS',
                    'user_id' => $userId,
                ]);
            } catch (\Throwable $e) {}

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
        }
    }

    private function doEditGoal(Connection $conn, int $accId, string $goalAccCol, int $goalId, string $nom, float $target, string $dateLimite, int $priorite): void
    {
        if ($accId <= 0 || $goalId <= 0) return;

        $goal = $conn->fetchAssociative(
            "SELECT id FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
            ['gid' => $goalId, 'acc' => $accId]
        );
        if (!$goal) return;

        $data = [
            'nom' => ($nom !== '' ? $nom : null),
            'montant_cible' => ($target > 0 ? $target : null),
            'date_limite' => ($dateLimite !== '' ? $dateLimite : null),
            'priorite' => min(5, max(1, $priorite)),
        ];

        $data = array_filter($data, fn($v) => $v !== null);

        if (!empty($data)) {
            $conn->update('financial_goal', $data, ['id' => $goalId]);
        }
    }

    private function doDeleteGoal(Connection $conn, int $accId, string $goalAccCol, int $goalId): void
    {
        if ($accId <= 0 || $goalId <= 0) return;

        $conn->executeStatement(
            "DELETE FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc",
            ['gid' => $goalId, 'acc' => $accId]
        );
    }

    // ✅ NEW: Transactions helpers for Savings History actions
    private function doDeleteTransaction(Connection $conn, int $userId, int $txId): void
    {
        if ($txId <= 0) return;

        $conn->executeStatement(
            "DELETE FROM `transaction`
             WHERE id = :id AND user_id = :uid AND module_source = :src",
            ['id' => $txId, 'uid' => $userId, 'src' => 'SAVINGS']
        );
    }

    private function doEditTransaction(Connection $conn, int $userId, int $txId, float $amount, string $description, ?string $dateRaw): void
    {
        if ($txId <= 0 || $amount <= 0) return;

        $exists = $conn->fetchOne(
            "SELECT 1 FROM `transaction`
             WHERE id = :id AND user_id = :uid AND module_source = :src
             LIMIT 1",
            ['id' => $txId, 'uid' => $userId, 'src' => 'SAVINGS']
        );
        if (!$exists) return;

        $data = [
            'montant' => $amount,
            'description' => $description,
        ];

        if ($dateRaw !== null && trim($dateRaw) !== '') {
            $data['date'] = str_replace('T', ' ', trim($dateRaw)) . ':00';
        }

        $conn->update('transaction', $data, ['id' => $txId]);
    }

    // ----------------------------
    // Dashboard (GET + POST)
    // ----------------------------

    #[Route('/savings', name: 'app_savings', methods: ['GET', 'POST'])]
    #[Route('/savings/index', name: 'app_savings_index', methods: ['GET', 'POST'])]
    public function index(Connection $conn, Security $security, Request $request): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);

        $tab = (string) $request->query->get('tab', 'savings');
        if (!in_array($tab, ['savings', 'goals'], true)) $tab = 'savings';

        $goalAccCol = $this->goalAccountFkColumn($conn);

        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $accUserCol     = $accPack['accUserCol'];
        $accBalanceCol  = $accPack['accBalanceCol'];
        $accounts       = $accPack['accounts'];
        $currentAccount = $accPack['currentAccount'];
        $accId          = $accPack['accId'];
        $accPkCol       = $accPack['accPkCol'];

        // ✅ Handle POST actions here
        if ($request->isMethod('POST')) {
            $formType = (string) $request->request->get('_form', '');

            // 1) Deposit
            if ($formType === 'deposit') {
                $amount = $this->safeFloat($request->request->get('amount'));
                $desc   = trim((string) $request->request->get('description', ''));

                $this->doDeposit($conn, $userId, $accId, $accUserCol, $accBalanceCol, $accPkCol, $amount, $desc);

                return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
            }

            // 2) Add goal
            if ($formType === 'add_goal') {
                $nom        = trim((string) $request->request->get('nom'));
                $target     = $this->safeFloat($request->request->get('montant_cible'));
                $current    = $this->safeFloat($request->request->get('montant_actuel'));
                $dateLimite = (string) $request->request->get('date_limite', '');
                $priorite   = $this->safeInt($request->request->get('priorite'), 3);

                $this->doAddGoal($conn, $accId, $goalAccCol, $nom, $target, $current, $dateLimite, $priorite);

                return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
            }

            // 3) Contribute to goal
            if ($formType === 'contribute_goal') {
                $gid = (int) $request->request->get('goal_id', 0);
                $add = $this->safeFloat($request->request->get('add_amount'));

                $this->doContributeGoal($conn, $userId, $accId, $goalAccCol, $accUserCol, $accBalanceCol, $accPkCol, $gid, $add);

                return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
            }

            // 4) Edit goal (optional)
            if ($formType === 'edit_goal') {
                $gid        = (int) $request->request->get('goal_id', 0);
                $nom        = trim((string) $request->request->get('nom', ''));
                $target     = $this->safeFloat($request->request->get('montant_cible'));
                $dateLimite = (string) $request->request->get('date_limite', '');
                $priorite   = $this->safeInt($request->request->get('priorite'), 3);

                $this->doEditGoal($conn, $accId, $goalAccCol, $gid, $nom, $target, $dateLimite, $priorite);

                return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
            }

            // 5) Delete goal (optional)
            if ($formType === 'delete_goal') {
                $gid = (int) $request->request->get('goal_id', 0);

                $this->doDeleteGoal($conn, $accId, $goalAccCol, $gid);

                return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
            }

            return $this->redirectToRoute('app_savings_index', ['tab' => $tab]);
        }

        // ----------------------------
        // Goals list + search + sort
        // ----------------------------
        $q = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'deadline_asc');

        $sort = $this->clampSort($sort, [
            'deadline_asc','deadline_desc',
            'priority_desc','priority_asc',
            'progress_desc','progress_asc',
            'name_asc','name_desc',
            'id_desc','id_asc',
        ], 'deadline_asc');

        $orderBy = match ($sort) {
            'deadline_desc' => "date_limite DESC",
            'priority_desc' => "priorite DESC",
            'priority_asc'  => "priorite ASC",
            'progress_desc' => "(CASE WHEN montant_cible > 0 THEN (montant_actuel / montant_cible) ELSE 0 END) DESC",
            'progress_asc'  => "(CASE WHEN montant_cible > 0 THEN (montant_actuel / montant_cible) ELSE 0 END) ASC",
            'name_asc'      => "nom ASC",
            'name_desc'     => "nom DESC",
            'id_asc'        => "id ASC",
            'id_desc'       => "id DESC",
            default         => "date_limite ASC",
        };

        $params = ['acc' => $accId];
        $where = "WHERE `$goalAccCol` = :acc";

        if ($q !== '') {
            $where .= " AND (
                nom LIKE :q
                OR CAST(priorite AS CHAR) LIKE :q
                OR CAST(montant_cible AS CHAR) LIKE :q
                OR CAST(montant_actuel AS CHAR) LIKE :q
                OR CAST(date_limite AS CHAR) LIKE :q
            )";
            $params['q'] = '%' . $q . '%';
        }

        $goals = ($accId > 0) ? $conn->fetchAllAssociative(
            "SELECT id, nom, montant_cible, montant_actuel, date_limite, priorite
             FROM financial_goal
             $where
             ORDER BY $orderBy",
            $params
        ) : [];

        // ----------------------------
        // Stats
        // ----------------------------
        $balance = $currentAccount ? $this->safeFloat($currentAccount[$accBalanceCol] ?? 0) : 0.0;

        $activeGoals = 0;
        $avgProgress = 0.0;
        $nearestDeadline = null;

        $progressSum = 0.0;
        foreach ($goals as $g) {
            $target = $this->safeFloat($g['montant_cible'] ?? 0);
            $curr   = $this->safeFloat($g['montant_actuel'] ?? 0);

            if ($target > 0 && $curr < $target) $activeGoals++;

            $pct = ($target > 0) ? min(100.0, ($curr / $target) * 100.0) : 0.0;
            $progressSum += $pct;

            if (!empty($g['date_limite'])) {
                if ($nearestDeadline === null || $g['date_limite'] < $nearestDeadline) {
                    $nearestDeadline = $g['date_limite'];
                }
            }
        }
        if (count($goals) > 0) $avgProgress = $progressSum / count($goals);

        $stats = [
            'balance' => $balance,
            'activeGoals' => $activeGoals,
            'avgProgress' => (int) round($avgProgress),
            'nearestDeadline' => $nearestDeadline ?? '--/--/----',
        ];

        // ----------------------------
        // Savings History (REAL transactions)
        // ----------------------------
     // ----------------------------
// Savings History (search/sort/filter)
// ----------------------------
$tx_q     = trim((string) $request->query->get('tx_q', ''));
$tx_sort  = (string) $request->query->get('tx_sort', 'date_desc');
$tx_range = (string) $request->query->get('tx_range', 'all');

$paramsTx = [
    'uid' => $userId,
    'src' => 'SAVINGS'
];

$whereTx = "WHERE user_id = :uid AND module_source = :src";

// Range filter
if ($tx_range === 'today') {
    $whereTx .= " AND DATE(`date`) = CURDATE() ";
} elseif ($tx_range === 'week') {
    $whereTx .= " AND YEARWEEK(`date`, 1) = YEARWEEK(CURDATE(), 1) ";
} elseif ($tx_range === 'month') {
    $whereTx .= " AND YEAR(`date`) = YEAR(CURDATE()) AND MONTH(`date`) = MONTH(CURDATE()) ";
}

// Search filter
if ($tx_q !== '') {
    $whereTx .= " AND (
        CAST(id AS CHAR) LIKE :q OR
        type LIKE :q OR
        CAST(montant AS CHAR) LIKE :q OR
        CAST(`date` AS CHAR) LIKE :q OR
        description LIKE :q
    )";
    $paramsTx['q'] = '%' . $tx_q . '%';
}

// Sort
$orderTx = match ($tx_sort) {
    'date_asc'     => "`date` ASC",
    'amount_desc'  => "montant DESC",
    'amount_asc'   => "montant ASC",
    'desc_asc'     => "description ASC",
    'desc_desc'    => "description DESC",
    default        => "`date` DESC",
};

$transactions = $conn->fetchAllAssociative(
    "SELECT id, type, montant, `date`, description
     FROM `transaction`
     $whereTx
     ORDER BY $orderTx",
    $paramsTx
);

// Optional stats for your modal (works with current filter)
$tx_stats = [
    'total' => count($transactions),
    'sum'   => 0,
    'avg'   => 0,
    'max'   => 0,
];

if (!empty($transactions)) {
    $sum = 0.0; $max = 0.0;
    foreach ($transactions as $t) {
        $m = (float) ($t['montant'] ?? 0);
        $sum += $m;
        if ($m > $max) $max = $m;
    }
    $tx_stats['sum'] = $sum;
    $tx_stats['max'] = $max;
    $tx_stats['avg'] = $sum / max(1, count($transactions));
}

// ----------------------------------
// Stats by ANY attribute (charts)
// ----------------------------------
$stat_by = (string) $request->query->get('stat_by', 'type');

$allowedStatBy = ['type', 'day', 'month', 'amount_bucket', 'description'];
if (!in_array($stat_by, $allowedStatBy, true)) {
    $stat_by = 'type';
}

$bucket = function (float $m): string {
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
    $m    = (float) ($t['montant'] ?? 0);
    $dt   = (string) ($t['date'] ?? '');

    $dayKey   = $dt ? substr($dt, 0, 10) : 'Unknown';
    $monthKey = $dt ? substr($dt, 0, 7)  : 'Unknown';

    $key = match ($stat_by) {
        'type' => $type !== '' ? $type : 'Unknown',
        'day' => $dayKey,
        'month' => $monthKey,
        'amount_bucket' => $bucket($m),
        'description' => trim($desc) !== '' ? trim($desc) : 'No description',
        default => 'Unknown',
    };

    if (!isset($statMap[$key])) {
        $statMap[$key] = 0.0;
    }
    $statMap[$key] += $m;
}

ksort($statMap);

$stat_labels = array_keys($statMap);
$stat_values = array_values($statMap);

        return $this->render('savings/index.html.twig', [
            'tab' => $tab,
            'stats' => $stats,
            'savingAccount' => $currentAccount ?? [],
            'accounts' => $accounts,
            'transactions' => $transactions,
            'goals' => $goals,
            'q' => $q,
            'sort' => $sort,
            'tx_q' => $tx_q,
            'tx_sort' => $tx_sort,
            'tx_range' => $tx_range,
            'tx_stats' => $tx_stats,
            'stat_by' => $stat_by,
            'stat_labels' => $stat_labels,
            'stat_values' => $stat_values,

        ]);
    }

    // -------------------------------------------------
    // Routes (used by Twig)
    // -------------------------------------------------

    #[Route('/savings/deposit', name: 'app_savings_deposit', methods: ['POST'])]
    public function deposit(Connection $conn, Security $security, Request $request): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);

        $amount = $this->safeFloat($request->request->get('amount'));
        $desc   = trim((string) $request->request->get('description', ''));

        $this->doDeposit(
            $conn,
            $userId,
            (int) $accPack['accId'],
            (string) $accPack['accUserCol'],
            (string) $accPack['accBalanceCol'],
            (string) $accPack['accPkCol'],
            $amount,
            $desc
        );

        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }

    // ✅ NEW ROUTES: Savings History edit/delete (transactions)
    #[Route('/savings/tx/{id}/delete', name: 'app_savings_tx_delete', methods: ['POST'])]
    public function txDelete(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('delete_tx_' . $id, $token)) {
            return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
        }

        $this->doDeleteTransaction($conn, $userId, $id);

        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }

    #[Route('/savings/tx/{id}/edit', name: 'app_savings_tx_edit', methods: ['POST'])]
    public function txEdit(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);

        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('edit_tx_' . $id, $token)) {
            return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
        }

        $amount = $this->safeFloat($request->request->get('montant'));
        $desc   = trim((string) $request->request->get('description', ''));
        $date   = $request->request->get('date'); // can be null

        $this->doEditTransaction($conn, $userId, $id, $amount, $desc, is_string($date) ? $date : null);

        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }

    #[Route('/savings/goal/new', name: 'app_goal_new', methods: ['POST'])]
    public function goalNew(Connection $conn, Security $security, Request $request): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);

        $goalAccCol = $this->goalAccountFkColumn($conn);

        $nom        = trim((string) $request->request->get('nom'));
        $target     = $this->safeFloat($request->request->get('montant_cible'));
        $current    = $this->safeFloat($request->request->get('montant_actuel'));
        $dateLimite = (string) $request->request->get('date_limite', '');
        $priorite   = $this->safeInt($request->request->get('priorite'), 3);

        $this->doAddGoal(
            $conn,
            (int) $accPack['accId'],
            $goalAccCol,
            $nom,
            $target,
            $current,
            $dateLimite,
            $priorite
        );

        return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
    }

    #[Route('/savings/goal/{id}/edit', name: 'app_goal_edit', methods: ['POST'])]
    public function goalEdit(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $nom        = trim((string) $request->request->get('nom', ''));
        $target     = $this->safeFloat($request->request->get('montant_cible'));
        $dateLimite = (string) $request->request->get('date_limite', '');
        $priorite   = $this->safeInt($request->request->get('priorite'), 3);

        $this->doEditGoal(
            $conn,
            (int) $accPack['accId'],
            $goalAccCol,
            $id,
            $nom,
            $target,
            $dateLimite,
            $priorite
        );

        return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
    }

    #[Route('/savings/goal/{id}/delete', name: 'app_goal_delete', methods: ['POST'])]
    public function goalDelete(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $this->doDeleteGoal(
            $conn,
            (int) $accPack['accId'],
            $goalAccCol,
            $id
        );

        return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
    }

    #[Route('/savings/goal/{id}/contribute', name: 'app_goal_contribute', methods: ['POST'])]
    public function goalContribute(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);

        $goalAccCol = $this->goalAccountFkColumn($conn);

        $add = $this->safeFloat($request->request->get('add_amount'));

        $this->doContributeGoal(
            $conn,
            $userId,
            (int) $accPack['accId'],
            $goalAccCol,
            (string) $accPack['accUserCol'],
            (string) $accPack['accBalanceCol'],
            (string) $accPack['accPkCol'],
            $id,
            $add
        );

        return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
    }

    #[Route('/savings/account/{id}/edit', name: 'app_savings_edit', methods: ['GET', 'POST'])]
    public function editAccount(int $id): Response
    {
        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }

    #[Route('/savings/account/{id}/delete', name: 'app_savings_delete', methods: ['POST'])]
    public function deleteAccount(int $id): Response
    {
        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }
    #[Route('/savings/rate/update', name: 'app_savings_rate_update', methods: ['POST'])]
public function updateRate(Connection $conn, Security $security, Request $request): Response
{
    $userId = $this->resolveUserId($conn, $security, $request);
    $accPack = $this->getOrCreateCurrentAccount($conn, $userId);

    $accId      = (int) $accPack['accId'];
    $accPkCol   = (string) $accPack['accPkCol'];
    $accUserCol = (string) $accPack['accUserCol'];

    // Read and clamp
    $rate = $this->safeFloat($request->request->get('taux_interet'));
    $rate = max(0, min(100, $rate)); // 0..100

    // Only update if column exists
    $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `saving_account`");
    $colsLower = array_map('strtolower', $cols);

    // Detect the real column name (taux_interet / tauxInteret / etc.)
    $rateCol = null;
    foreach ($cols as $c) {
        if (strtolower($c) === 'taux_interet' || strtolower($c) === 'tauxinteret') {
            $rateCol = $c;
            break;
        }
    }

    if ($rateCol && $accId > 0) {
        $conn->executeStatement(
            "UPDATE saving_account
             SET `$rateCol` = :r
             WHERE `$accPkCol` = :id AND `$accUserCol` = :uid",
            ['r' => $rate, 'id' => $accId, 'uid' => $userId]
        );
    }

    return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
}
#[Route('/savings/export/csv', name: 'app_savings_export_csv', methods: ['GET'])]
public function exportCsv(Connection $conn, Security $security, Request $request): Response
{
    $userId = $this->resolveUserId($conn, $security, $request);

    // Optional filters from UI (same names used in Twig)
    $q     = trim((string) $request->query->get('tx_q', ''));
    $sort  = (string) $request->query->get('tx_sort', 'date_desc');
    $range = (string) $request->query->get('tx_range', 'all');

    // Date range filter
    $dateWhere = '';
    $params = ['uid' => $userId, 'src' => 'SAVINGS'];

    if ($range === 'today') {
        $dateWhere = " AND DATE(date) = CURDATE() ";
    } elseif ($range === 'week') {
        $dateWhere = " AND YEARWEEK(date, 1) = YEARWEEK(CURDATE(), 1) ";
    } elseif ($range === 'month') {
        $dateWhere = " AND YEAR(date) = YEAR(CURDATE()) AND MONTH(date) = MONTH(CURDATE()) ";
    }

    // Search filter
    $searchWhere = '';
    if ($q !== '') {
        $searchWhere = " AND (
            CAST(id AS CHAR) LIKE :q OR
            type LIKE :q OR
            CAST(montant AS CHAR) LIKE :q OR
            CAST(date AS CHAR) LIKE :q OR
            description LIKE :q
        )";
        $params['q'] = '%' . $q . '%';
    }

    // Sorting
    $orderBy = match ($sort) {
        'date_asc' => 'date ASC',
        'amount_desc' => 'montant DESC',
        'amount_asc' => 'montant ASC',
        'desc_asc' => 'description ASC',
        'desc_desc' => 'description DESC',
        default => 'date DESC',
    };

    $rows = $conn->fetchAllAssociative(
        "SELECT id, type, montant, date, description
         FROM `transaction`
         WHERE user_id = :uid AND module_source = :src
         $dateWhere
         $searchWhere
         ORDER BY $orderBy",
        $params
    );

    // Build CSV
    $out = fopen('php://temp', 'r+');
    fputcsv($out, ['ID', 'Type', 'Amount', 'Date', 'Description']);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['id'] ?? '',
            $r['type'] ?? '',
            $r['montant'] ?? '',
            $r['date'] ?? '',
            $r['description'] ?? '',
        ]);
    }

    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);

    return new Response(
        $csv,
        200,
        [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="savings_transactions.csv"',
        ]
    );
}
#[Route('/savings/export/pdf', name: 'app_savings_export_pdf', methods: ['GET'])]
public function exportPdf(Connection $conn, Security $security, Request $request): Response
{
    $userId = $this->resolveUserId($conn, $security, $request);

    $q     = trim((string) $request->query->get('tx_q', ''));
    $sort  = (string) $request->query->get('tx_sort', 'date_desc');
    $range = (string) $request->query->get('tx_range', 'all');

    $dateWhere = '';
    $params = ['uid' => $userId, 'src' => 'SAVINGS'];

    if ($range === 'today') {
        $dateWhere = " AND DATE(date) = CURDATE() ";
    } elseif ($range === 'week') {
        $dateWhere = " AND YEARWEEK(date, 1) = YEARWEEK(CURDATE(), 1) ";
    } elseif ($range === 'month') {
        $dateWhere = " AND YEAR(date) = YEAR(CURDATE()) AND MONTH(date) = MONTH(CURDATE()) ";
    }

    $searchWhere = '';
    if ($q !== '') {
        $searchWhere = " AND (
            CAST(id AS CHAR) LIKE :q OR
            type LIKE :q OR
            CAST(montant AS CHAR) LIKE :q OR
            CAST(date AS CHAR) LIKE :q OR
            description LIKE :q
        )";
        $params['q'] = '%' . $q . '%';
    }

    $orderBy = match ($sort) {
        'date_asc' => 'date ASC',
        'amount_desc' => 'montant DESC',
        'amount_asc' => 'montant ASC',
        'desc_asc' => 'description ASC',
        'desc_desc' => 'description DESC',
        default => 'date DESC',
    };

    $rows = $conn->fetchAllAssociative(
        "SELECT id, type, montant, date, description
         FROM `transaction`
         WHERE user_id = :uid AND module_source = :src
         $dateWhere
         $searchWhere
         ORDER BY $orderBy",
        $params
    );

    // Render a printable template (you create it once)
    return $this->render('savings/print/transactions_pdf.html.twig', [
        'rows' => $rows,
        'range' => $range,
        'q' => $q,
        'sort' => $sort,
        'generatedAt' => new \DateTime(),
    ]);
}

}
