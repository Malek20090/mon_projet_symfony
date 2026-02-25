<?php

namespace App\Controller;

use App\Dto\SavingsTransactionCsvRow;
use App\Entity\FinancialGoal;
use App\Entity\SavingAccount;
use App\Service\SavingsCalendarService;
use App\Service\SavingsPdfService;
use App\Service\SavingsAssistantService;
use App\Service\SavingsGoalStatsService;
use App\Service\SavingsStatsService;
use App\Service\GoalWhatIfService;
use App\Service\GoalWhatIfAdvisor;
use App\Service\WhatIfAiNarratorService;
use Doctrine\DBAL\Connection;
use Knp\Component\Pager\PaginatorInterface;
use RichId\CsvGeneratorBundle\Configuration\CsvGeneratorConfiguration;
use RichId\CsvGeneratorBundle\Generator\CsvGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

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

        $candidates = ['saving_account_id', 'savingaccount_id', 'account_id', 'saving_account'];

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
                // try id
                try {
                    $id = $conn->fetchOne("SELECT id FROM `$userTable` WHERE email = :email LIMIT 1", [
                        'email' => $email
                    ]);
                    if ($id) return (int) $id;
                } catch (\Throwable $e) {}

                // try detected PK
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
    // Validation helpers
    // -------------------------------------------------

    private function flashErrors(array $errors): void
    {
        foreach ($errors as $e) {
            $this->addFlash('danger', '❌ ' . $e);
        }
    }

    private function parseYmd(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') return null;

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt instanceof \DateTimeImmutable) return $dt->format('Y-m-d');

        try {
            $dt2 = new \DateTimeImmutable($date);
            return $dt2->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function validateGoalInputs(
        string $nom,
        float $target,
        float $current,
        ?string $dateLimite,
        int $priorite,
        ValidatorInterface $validator
    ): array
    {
        $errors = [];
        $goal = new FinancialGoal();
        $goal->setNom($nom);
        $goal->setMontantCible($target);
        $goal->setMontantActuel($current);
        $goal->setPriorite($priorite);

        if ($dateLimite !== null) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dateLimite);
            if (!$dt) {
                $errors[] = "Date limite invalide.";
            } else {
                $goal->setDateLimite(\DateTime::createFromImmutable($dt));
            }
        }

        foreach (['nom', 'montantCible', 'montantActuel', 'dateLimite', 'priorite'] as $property) {
            $violations = $validator->validateProperty($goal, $property);
            foreach ($violations as $violation) {
                $errors[] = $violation->getMessage();
            }
        }

        return $errors;
    }

    private function validateDeposit(float $amount, ValidatorInterface $validator): array
    {
        $errors = [];
        $saving = new SavingAccount();
        $saving->setSold($amount);

        $violations = $validator->validateProperty($saving, 'sold');
        foreach ($violations as $violation) {
            $errors[] = $violation->getMessage();
        }

        return $errors;
    }

    private function validateRate(float $rate, ValidatorInterface $validator): array
    {
        $errors = [];
        $saving = new SavingAccount();
        $saving->setTauxInteret($rate);

        $violations = $validator->validateProperty($saving, 'tauxInteret');
        foreach ($violations as $violation) {
            $errors[] = $violation->getMessage();
        }

        return $errors;
    }

    private function validateContribution(float $add, ValidatorInterface $validator): array
    {
        $errors = [];
        if ($add <= 0) {
            $errors[] = 'Contribution amount must be greater than 0.';
            return $errors;
        }

        $saving = new SavingAccount();
        $saving->setSold($add);

        $violations = $validator->validateProperty($saving, 'sold');
        foreach ($violations as $violation) {
            $errors[] = $violation->getMessage();
        }

        return $errors;
    }

    // -------------------------------------------------
    // DB actions
    // -------------------------------------------------

    private function doDeposit(Connection $conn, int $userId, int $accId, string $accUserCol, string $accBalanceCol, string $accPkCol, float $amount, string $desc): void
    {
        if ($amount <= 0 || $accId <= 0) return;

        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                "UPDATE saving_account
                 SET `$accBalanceCol` = `$accBalanceCol` + :a
                 WHERE `$accPkCol` = :id AND `$accUserCol` = :uid",
                ['a' => $amount, 'id' => $accId, 'uid' => $userId]
            );

            // History row (table: `transaction`)
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
            throw $e; // important for debugging
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

            // optional history
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
            throw $e;
        }
    }

    private function doEditGoal(
        Connection $conn,
        int $userId,
        int $accId,
        string $goalAccCol,
        string $accUserCol,
        string $accBalanceCol,
        string $accPkCol,
        int $goalId,
        string $nom,
        float $target,
        string $dateLimite,
        int $priorite
    ): void
    {
        if ($accId <= 0 || $goalId <= 0) return;

        $goal = $conn->fetchAssociative(
            "SELECT id, montant_actuel FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
            ['gid' => $goalId, 'acc' => $accId]
        );
        if (!$goal) return;

        $currentAmount = (float) ($goal['montant_actuel'] ?? 0);
        $refund = 0.0;
        if ($target > 0 && $currentAmount > $target) {
            $refund = $currentAmount - $target;
        }

        $data = [
            'nom' => ($nom !== '' ? $nom : null),
            'montant_cible' => ($target > 0 ? $target : null),
            'date_limite' => ($dateLimite !== '' ? $dateLimite : null),
            'priorite' => min(5, max(1, $priorite)),
        ];
        if ($refund > 0) {
            $data['montant_actuel'] = $target;
        }

        $data = array_filter($data, fn($v) => $v !== null);

        $conn->beginTransaction();
        try {
            if (!empty($data)) {
                $conn->update('financial_goal', $data, ['id' => $goalId]);
            }

            if ($refund > 0) {
                $conn->executeStatement(
                    "UPDATE saving_account
                     SET `$accBalanceCol` = `$accBalanceCol` + :r
                     WHERE `$accPkCol` = :id AND `$accUserCol` = :uid",
                    ['r' => $refund, 'id' => $accId, 'uid' => $userId]
                );

                try {
                    $conn->insert('transaction', [
                        'type' => 'GOAL_REFUND',
                        'montant' => $refund,
                        'date' => (new \DateTime())->format('Y-m-d H:i:s'),
                        'description' => 'Auto-refund after reducing target of goal #' . $goalId,
                        'module_source' => 'SAVINGS',
                        'user_id' => $userId,
                    ]);
                } catch (\Throwable $e) {}
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
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
            // expects input type="datetime-local" => 2026-02-10T12:30
            $data['date'] = str_replace('T', ' ', trim($dateRaw)) . ':00';
        }

        $conn->update('transaction', $data, ['id' => $txId]);
    }

    private function roundToStep(float $value, int $step = 50): float
    {
        if ($step <= 0) {
            return round($value, 2);
        }

        return (float) (round($value / $step) * $step);
    }

    private function containsAssistantContributionSignal(string $message): bool
    {
        return preg_match('/\b(contribute|contribution|add|put|deposit|verser|ajouter|alimenter)\b/i', $message) === 1;
    }

    private function extractAssistantAmount(string $message): ?float
    {
        if (preg_match('/(\d+(?:[.,]\d{1,2})?)\s*(?:tnd|dt|dinar|dinars)?\b/i', $message, $m) !== 1) {
            return null;
        }

        $amount = (float) str_replace(',', '.', (string) ($m[1] ?? '0'));
        return $amount > 0 ? $amount : null;
    }

    private function extractAssistantGoalNameHint(string $message): ?string
    {
        if (preg_match('/goal\s*(?:named|name)?\s*[=:]?\s*[\'"]?([\p{L}\p{N}_\-\s]{1,80})[\'"]?/iu', $message, $m) === 1) {
            return trim((string) ($m[1] ?? ''));
        }

        if (preg_match('/objectif\s*(?:nomm[ée]|appel[ée])?\s*[=:]?\s*[\'"]?([\p{L}\p{N}_\-\s]{1,80})[\'"]?/iu', $message, $m) === 1) {
            return trim((string) ($m[1] ?? ''));
        }

        if (preg_match('/(?:to|for)\s+[\'"]?([\p{L}\p{N}_\-\s]{1,80})[\'"]?$/iu', trim($message), $m) === 1) {
            return trim((string) ($m[1] ?? ''));
        }

        return null;
    }

    private function resolveAssistantGoalFromMessage(array $goals, string $message): ?array
    {
        $q = mb_strtolower(trim($message));
        $hint = $this->extractAssistantGoalNameHint($message);
        $hintLower = $hint !== null ? mb_strtolower($hint) : null;

        if ($hintLower !== null && $hintLower !== '') {
            foreach ($goals as $g) {
                $name = mb_strtolower(trim((string) ($g['nom'] ?? '')));
                if ($name !== '' && ($name === $hintLower || str_contains($name, $hintLower) || str_contains($hintLower, $name))) {
                    return $g;
                }
            }
        }

        $best = null;
        $bestLen = 0;
        foreach ($goals as $g) {
            $name = mb_strtolower(trim((string) ($g['nom'] ?? '')));
            if ($name === '') {
                continue;
            }
            if (str_contains($q, $name) && mb_strlen($name) > $bestLen) {
                $best = $g;
                $bestLen = mb_strlen($name);
            }
        }

        return $best;
    }

    private function tryHandleAssistantContribution(
        Connection $conn,
        int $userId,
        array $accPack,
        string $goalAccCol,
        string $message,
        ValidatorInterface $validator
    ): ?array {
        if (!$this->containsAssistantContributionSignal($message)) {
            return null;
        }

        $accId = (int) ($accPack['accId'] ?? 0);
        if ($accId <= 0) {
            return [
                'ok' => false,
                'reply' => "No savings account was found for your profile.",
                'source' => 'local-action',
                'model' => null,
                'error' => 'NO_ACCOUNT',
            ];
        }

        $goals = $conn->fetchAllAssociative(
            "SELECT id, nom, montant_cible, montant_actuel
             FROM financial_goal
             WHERE `$goalAccCol` = :acc",
            ['acc' => $accId]
        );
        if (count($goals) === 0) {
            return [
                'ok' => false,
                'reply' => "You currently have no goals to contribute to.",
                'source' => 'local-action',
                'model' => null,
                'error' => 'NO_GOALS',
            ];
        }

        $amount = $this->extractAssistantAmount($message);
        if ($amount === null) {
            return [
                'ok' => false,
                'reply' => "I understood you want to contribute, but I couldn't read the amount.\nTry: \"contribute 400 TND to goal pcc\".",
                'source' => 'local-action',
                'model' => null,
                'error' => 'MISSING_AMOUNT',
            ];
        }

        $goal = $this->resolveAssistantGoalFromMessage($goals, $message);
        if (!$goal) {
            $names = array_map(static fn(array $g) => (string) ($g['nom'] ?? ''), array_slice($goals, 0, 6));
            $names = array_values(array_filter($names, static fn(string $n) => trim($n) !== ''));
            $known = count($names) ? implode(', ', $names) : 'none';
            return [
                'ok' => false,
                'reply' => "I found the amount ({$amount} TND) but couldn't match the goal name.\nKnown goals: {$known}.",
                'source' => 'local-action',
                'model' => null,
                'error' => 'GOAL_NOT_FOUND',
            ];
        }

        $errs = $this->validateContribution($amount, $validator);
        if ($errs) {
            return [
                'ok' => false,
                'reply' => implode(' ', $errs),
                'source' => 'local-action',
                'model' => null,
                'error' => 'INVALID_AMOUNT',
            ];
        }

        $goalId = (int) ($goal['id'] ?? 0);
        $before = $conn->fetchOne(
            "SELECT montant_actuel FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
            ['gid' => $goalId, 'acc' => $accId]
        );

        $this->doContributeGoal(
            $conn,
            $userId,
            $accId,
            $goalAccCol,
            (string) $accPack['accUserCol'],
            (string) $accPack['accBalanceCol'],
            (string) $accPack['accPkCol'],
            $goalId,
            $amount
        );

        $after = $conn->fetchOne(
            "SELECT montant_actuel FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
            ['gid' => $goalId, 'acc' => $accId]
        );
        if ($before !== null && $after !== null && (float) $after === (float) $before) {
            return [
                'ok' => false,
                'reply' => "Contribution refused: insufficient savings balance or invalid goal.",
                'source' => 'local-action',
                'model' => null,
                'error' => 'CONTRIB_REFUSED',
            ];
        }

        $newBalance = (float) $conn->fetchOne(
            "SELECT `{$accPack['accBalanceCol']}` FROM saving_account
             WHERE `{$accPack['accPkCol']}` = :id AND `{$accPack['accUserCol']}` = :uid LIMIT 1",
            ['id' => $accId, 'uid' => $userId]
        );

        $target = (float) ($goal['montant_cible'] ?? 0);
        $remaining = max(0.0, $target - (float) $after);
        $goalName = (string) ($goal['nom'] ?? ('goal #' . $goalId));
        $reply = sprintf(
            "Done. Added %.2f TND to goal \"%s\".\nRemaining for this goal: %.2f TND.\nNew savings balance: %.2f TND.",
            $amount,
            $goalName,
            $remaining,
            $newBalance
        );

        return [
            'ok' => true,
            'reply' => $reply,
            'source' => 'local-action',
            'model' => null,
            'error' => null,
        ];
    }

    private function simulateGoalTimeline(array $goalRows, float $balanceNow, float $oneTimeDeposit, float $monthlyDeposit): array
    {
        $items = [];
        foreach ($goalRows as $g) {
            $items[] = [
                'id' => (int) ($g['id'] ?? 0),
                'name' => (string) ($g['name'] ?? 'Goal'),
                'priority' => (int) ($g['priority'] ?? 3),
                'remaining' => max(0.0, (float) ($g['remaining'] ?? 0.0)),
                'deadline' => isset($g['deadline']) && $g['deadline'] !== '' ? (string) $g['deadline'] : null,
                'completedMonth' => null,
            ];
        }

        usort($items, static function (array $a, array $b): int {
            $cmp = ($a['priority'] <=> $b['priority']);
            if ($cmp !== 0) {
                return $cmp;
            }

            $ad = $a['deadline'] ?? '9999-12-31';
            $bd = $b['deadline'] ?? '9999-12-31';
            return strcmp((string) $ad, (string) $bd);
        });

        $pool = max(0.0, $balanceNow + $oneTimeDeposit);
        $monthly = max(0.0, $monthlyDeposit);
        $maxMonths = 600;
        $month = 0;

        while ($month <= $maxMonths) {
            foreach ($items as $k => $goal) {
                $remaining = (float) $goal['remaining'];
                if ($remaining <= 0.0 || $pool <= 0.0) {
                    continue;
                }

                $alloc = min($pool, $remaining);
                $items[$k]['remaining'] = round($remaining - $alloc, 2);
                $pool = round($pool - $alloc, 2);

                if ($items[$k]['remaining'] <= 0.0 && $items[$k]['completedMonth'] === null) {
                    $items[$k]['remaining'] = 0.0;
                    $items[$k]['completedMonth'] = $month;
                }
            }

            $unfinished = array_filter($items, static fn(array $x): bool => (float) $x['remaining'] > 0.0);
            if (count($unfinished) === 0) {
                break;
            }

            if ($monthly <= 0.0) {
                break;
            }

            $month++;
            $pool = round($pool + $monthly, 2);
        }

        $today = new \DateTimeImmutable('today');
        $timeline = [];
        $goalsAtRisk = 0;
        $firstAtRisk = null;
        $maxCompletedMonth = 0;
        $allCompleted = true;

        foreach ($items as $goal) {
            $completedMonth = $goal['completedMonth'];
            $projectedDate = null;
            if (is_int($completedMonth)) {
                $projectedDate = $today->modify('+' . $completedMonth . ' month')->format('Y-m-d');
                $maxCompletedMonth = max($maxCompletedMonth, $completedMonth);
            } else {
                $allCompleted = false;
            }

            $atRisk = false;
            if ($goal['deadline'] !== null) {
                if ($projectedDate === null || strcmp($projectedDate, $goal['deadline']) > 0) {
                    $atRisk = true;
                    $goalsAtRisk++;
                    if ($firstAtRisk === null) {
                        $firstAtRisk = [
                            'id' => (int) $goal['id'],
                            'name' => (string) $goal['name'],
                            'deadline' => (string) $goal['deadline'],
                            'projectedDate' => $projectedDate,
                        ];
                    }
                }
            }

            $timeline[] = [
                'id' => (int) $goal['id'],
                'name' => (string) $goal['name'],
                'priority' => (int) $goal['priority'],
                'deadline' => $goal['deadline'],
                'projectedDate' => $projectedDate,
                'monthsToFinish' => $completedMonth,
                'atRisk' => $atRisk,
            ];
        }

        return [
            'monthsToFinish' => $allCompleted ? $maxCompletedMonth : null,
            'projectedFinishDate' => $allCompleted ? $today->modify('+' . $maxCompletedMonth . ' month')->format('Y-m-d') : null,
            'goalsAtRisk' => $goalsAtRisk,
            'firstAtRisk' => $firstAtRisk,
            'timeline' => $timeline,
            'allCompleted' => $allCompleted,
        ];
    }

    private function pickVariant(array $options): string
    {
        if (count($options) === 0) {
            return '';
        }
        $idx = random_int(0, count($options) - 1);
        return (string) ($options[$idx] ?? '');
    }

    private function buildConflictAnalysis(
        array $goalRows,
        float $balance,
        float $oneTimeDeposit,
        float $monthlyDeposit
    ): array {
        if (count($goalRows) < 2) {
            return ['summary' => 'Not enough goals to run conflict analysis.', 'scenarios' => []];
        }

        $findGoalIndex = static function (array $rows, array $keywords): ?int {
            foreach ($rows as $i => $g) {
                $name = strtolower((string) ($g['name'] ?? ''));
                foreach ($keywords as $kw) {
                    if (str_contains($name, $kw)) {
                        return $i;
                    }
                }
            }
            return null;
        };

        $scenarios = [];

        $travelIdx = $findGoalIndex($goalRows, ['travel', 'vacation', 'trip', 'voyage']);
        if ($travelIdx !== null) {
            $goalsA = $goalRows;
            $dropped = $goalsA[$travelIdx]['name'] ?? 'Travel';
            unset($goalsA[$travelIdx]);
            $goalsA = array_values($goalsA);
            $packA = $this->simulateGoalTimeline($goalsA, $balance, $oneTimeDeposit, $monthlyDeposit);
            $scenarios[] = [
                'code' => 'A',
                'title' => 'Delay travel goal',
                'drop' => (string) $dropped,
                'months' => $packA['monthsToFinish'],
                'riskGoals' => (int) ($packA['goalsAtRisk'] ?? 0),
                'note' => 'Frees cash for urgent goals first.',
            ];
        }

        $emergencyIdx = $findGoalIndex($goalRows, ['emergency', 'urgent', 'imprevu', 'secours', 'buffer']);
        if ($emergencyIdx !== null) {
            $goalsB = $goalRows;
            $currentRemaining = (float) ($goalsB[$emergencyIdx]['remaining'] ?? 0.0);
            $goalsB[$emergencyIdx]['remaining'] = round($currentRemaining * 0.7, 2);
            $packB = $this->simulateGoalTimeline($goalsB, $balance, $oneTimeDeposit, $monthlyDeposit);
            $scenarios[] = [
                'code' => 'B',
                'title' => 'Reduce emergency target',
                'drop' => 'Emergency buffer to 70%',
                'months' => $packB['monthsToFinish'],
                'riskGoals' => (int) ($packB['goalsAtRisk'] ?? 0),
                'note' => 'Faster progress but lower safety cushion.',
            ];
        }

        $packC = $this->simulateGoalTimeline($goalRows, $balance, $oneTimeDeposit, $monthlyDeposit + 300.0);
        $scenarios[] = [
            'code' => 'C',
            'title' => 'Increase income by 300 TND',
            'drop' => 'No goal dropped',
            'months' => $packC['monthsToFinish'],
            'riskGoals' => (int) ($packC['goalsAtRisk'] ?? 0),
            'note' => 'Keeps all goals while improving completion speed.',
        ];

        $summary = 'You cannot safely reach all goals with current cashflow.';
        foreach ($scenarios as $s) {
            if (($s['riskGoals'] ?? 1) === 0) {
                $summary = 'At least one strategic scenario can make goals safer.';
                break;
            }
        }

        return ['summary' => $summary, 'scenarios' => $scenarios];
    }

    private function buildStayLazyProjection(float $remainingAfterNow, float $monthlyDeposit): array
    {
        $inflationLoss = round($remainingAfterNow * 0.11, 2);
        $opportunityCost = round(($monthlyDeposit * 36) + $inflationLoss, 2);

        return [
            'years' => 3,
            'completedGoals' => 0,
            'inflationLoss' => $inflationLoss,
            'opportunityCost' => $opportunityCost,
        ];
    }

    private function buildTimelineSeries(float $remainingAfterNow, float $monthlyDeposit, ?int $monthsToFinish): array
    {
        $limit = $monthsToFinish !== null ? min(12, max(1, $monthsToFinish)) : 12;
        $series = [];
        for ($m = 0; $m <= $limit; $m++) {
            $remaining = max(0.0, $remainingAfterNow - ($monthlyDeposit * $m));
            $series[] = ['month' => $m, 'remaining' => round($remaining, 2)];
        }
        return $series;
    }

    private function buildAssistantDbContext(Connection $conn, int $userId): array
    {
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);
        $balance = $this->safeFloat(($accPack['currentAccount'][$accPack['accBalanceCol']] ?? 0));

        $goals = [];
        if ((int) $accPack['accId'] > 0) {
            $goals = $conn->fetchAllAssociative(
                "SELECT id, nom, montant_cible, montant_actuel, date_limite, priorite
                 FROM financial_goal
                 WHERE `$goalAccCol` = :acc
                 ORDER BY priorite ASC, date_limite ASC",
                ['acc' => (int) $accPack['accId']]
            );
        }

        $goalRows = [];
        $remainingTotal = 0.0;
        foreach ($goals as $g) {
            $target = max(0.0, $this->safeFloat($g['montant_cible'] ?? 0));
            $current = max(0.0, $this->safeFloat($g['montant_actuel'] ?? 0));
            $remaining = max(0.0, $target - $current);
            $remainingTotal += $remaining;
            $goalRows[] = [
                'id' => (int) ($g['id'] ?? 0),
                'name' => (string) ($g['nom'] ?? 'Goal'),
                'priority' => (int) ($g['priorite'] ?? 3),
                'target' => round($target, 2),
                'current' => round($current, 2),
                'remaining' => round($remaining, 2),
                'deadline' => !empty($g['date_limite']) ? (string) $g['date_limite'] : null,
            ];
        }

        $timelinePack = $this->simulateGoalTimeline($goalRows, $balance, 0.0, 0.0);
        $expenseByCategory = [];
        $revenueByType = [];
        $income30d = 0.0;
        $expense30d = 0.0;

        try {
            $income30d = (float) $conn->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) FROM revenue
                 WHERE user_id = :uid
                 AND received_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                ['uid' => $userId]
            );
        } catch (\Throwable $e) {
        }

        try {
            $expense30d = (float) $conn->fetchOne(
                "SELECT COALESCE(SUM(amount), 0) FROM expense
                 WHERE user_id = :uid
                 AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)",
                ['uid' => $userId]
            );
        } catch (\Throwable $e) {
        }

        try {
            $expenseByCategory = $conn->fetchAllAssociative(
                "SELECT COALESCE(NULLIF(TRIM(category), ''), 'Other') AS category, COALESCE(SUM(amount), 0) AS total
                 FROM expense
                 WHERE user_id = :uid
                 AND expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY category
                 ORDER BY total DESC
                 LIMIT 5",
                ['uid' => $userId]
            );
        } catch (\Throwable $e) {
        }

        try {
            $revenueByType = $conn->fetchAllAssociative(
                "SELECT COALESCE(NULLIF(TRIM(type), ''), 'income') AS type, COALESCE(SUM(amount), 0) AS total
                 FROM revenue
                 WHERE user_id = :uid
                 AND received_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY type
                 ORDER BY total DESC
                 LIMIT 5",
                ['uid' => $userId]
            );
        } catch (\Throwable $e) {
        }

        return [
            'userId' => $userId,
            'balanceNow' => round($balance, 2),
            'goalsCount' => count($goalRows),
            'goalsAtRisk' => (int) ($timelinePack['goalsAtRisk'] ?? 0),
            'topGoal' => $goalRows[0] ?? null,
            'remainingTotal' => round($remainingTotal, 2),
            'income30d' => round($income30d, 2),
            'expense30d' => round($expense30d, 2),
            'net30d' => round($income30d - $expense30d, 2),
            'expenseByCategory30d' => $expenseByCategory,
            'revenueByType30d' => $revenueByType,
            'goals' => array_slice($goalRows, 0, 10),
        ];
    }

    private function buildWhatIfFallbackAdvice(
        array $scenario,
        array $result,
        array $history,
        array $alternatives,
        array $expenseByCategory,
        array $revenueByType,
        string $persona,
        bool $brutalTruth,
        array $conflictAnalysis,
        array $stayLazyProjection,
        array $goalTimeline,
        string $runId = ''
    ): string
    {
        $selectedGoalName = (string) ($scenario['selectedGoalName'] ?? 'selected goal');
        $finishDate = (string) ($result['projectedFinishDate'] ?? 'n/a');
        $riskLevel = strtoupper((string) ($result['riskLevel'] ?? 'UNKNOWN'));
        $riskGoals = (int) ($result['goalsAtRisk'] ?? 0);
        $feasibilityScore = (float) ($result['feasibilityScore'] ?? 0.0);
        $stressIndex = (float) ($result['stressIndex'] ?? 0.0);
        $accGain = (float) ($result['accelerationGainMonths'] ?? 0.0);
        $requiredAdjustment = (float) ($result['requiredAdjustmentTnd'] ?? 0.0);
        $overcommit = (bool) ($result['overcommitmentFlag'] ?? false);
        $recommendedMonthly = (float) ($result['recommendedMonthly'] ?? 0.0);
        $monthlyNow = (float) ($scenario['monthlyDeposit'] ?? 0.0);
        $remaining = (float) ($result['remainingAfterNow'] ?? 0.0);
        $net30d = (float) ($history['net30d'] ?? 0.0);
        $monthsText = $result['monthsToFinish'] === null ? 'n/a' : ((int) $result['monthsToFinish'] . ' month(s)');

        $toneHeader = "Based on current projections:";
        if ($stressIndex >= 70.0) {
            $toneHeader = "Based on current projections, this configuration is fragile:";
        } elseif ($feasibilityScore < 60.0) {
            $toneHeader = "Based on current projections, this scenario is under-realistic:";
        } elseif ($accGain >= 3.0) {
            $toneHeader = "Based on current projections, this scenario is a strong optimization:";
        }

        $bestAlt = null;
        foreach ($alternatives as $alt) {
            if (($alt['monthsToFinish'] ?? null) === null) {
                continue;
            }
            if ($bestAlt === null || (int) ($alt['goalsAtRisk'] ?? 999) < (int) ($bestAlt['goalsAtRisk'] ?? 999)) {
                $bestAlt = $alt;
                continue;
            }
            if ((int) ($alt['goalsAtRisk'] ?? 999) === (int) ($bestAlt['goalsAtRisk'] ?? 999)
                && (int) ($alt['monthsToFinish'] ?? 999) < (int) ($bestAlt['monthsToFinish'] ?? 999)) {
                $bestAlt = $alt;
            }
        }

        $topCut = null;
        if (!empty($expenseByCategory)) {
            $row = $expenseByCategory[0];
            if (is_array($row)) {
                $cat = (string) ($row['category'] ?? 'Other');
                $amt = (float) ($row['total'] ?? 0.0);
                $topCut = ['category' => $cat, 'monthly' => round($amt * 0.12, 2)];
            }
        }

        $lines = [];
        $lines[] = "Executive Insight:";
        $lines[] = $toneHeader;
        $lines[] = sprintf(
            "- Goal \"%s\": finish date %s, risk level %s, feasibility %.1f/100.",
            $selectedGoalName,
            $finishDate,
            $riskLevel,
            $feasibilityScore
        );
        $lines[] = sprintf(
            "- Remaining %.2f TND, monthly deposit %.2f TND, stress index %.1f/100.",
            $remaining,
            $monthlyNow,
            $stressIndex
        );

        $lines[] = "Quantified Improvement Path:";
        $lines[] = sprintf(
            "- Acceleration gain: %+.1f month(s). Required adjustment: %+.2f TND/month.",
            $accGain,
            $requiredAdjustment
        );
        if ($recommendedMonthly > 0.0) {
            $lines[] = sprintf(
                "- Engine target monthly deposit: %.2f TND (current %.2f TND).",
                $recommendedMonthly,
                $monthlyNow
            );
        }
        if ($bestAlt !== null) {
            $lines[] = sprintf(
                "- Best tested option: %.0f TND/month -> %s, risk %d.",
                (float) ($bestAlt['monthlyDeposit'] ?? 0.0),
                ($bestAlt['monthsToFinish'] ?? null) === null ? 'n/a' : ((int) $bestAlt['monthsToFinish'] . ' month(s)'),
                (int) ($bestAlt['goalsAtRisk'] ?? 0)
            );
        }

        $lines[] = "Risk Interpretation:";
        $lines[] = sprintf(
            "- Deadline risk count: %d. Overcommitment flag: %s.",
            $riskGoals,
            $overcommit ? 'ON' : 'OFF'
        );
        if ($overcommit) {
            $lines[] = sprintf(
                "- Monthly plan exceeds recent net cashflow (net30d %.2f TND). Execution risk is elevated.",
                $net30d
            );
        } elseif ($stressIndex < 35.0) {
            $lines[] = "- Execution pressure is controlled; timeline stability is acceptable.";
        } else {
            $lines[] = "- Execution pressure is moderate; keep weekly tracking strict.";
        }

        $lines[] = "Strategic Action:";
        if ($requiredAdjustment > 0) {
            $lines[] = sprintf(
                "- Increase monthly contribution by %.2f TND to align with engine target.",
                $requiredAdjustment
            );
        } elseif ($requiredAdjustment < 0) {
            $lines[] = sprintf(
                "- You can reduce monthly contribution by %.2f TND and keep the current timeline.",
                abs($requiredAdjustment)
            );
        } else {
            $lines[] = "- Keep current monthly contribution; no structural adjustment required.";
        }
        if ($topCut !== null) {
            $lines[] = sprintf(
                "- Structural move: cut %s by ~%.2f TND/month and redirect to this goal.",
                (string) $topCut['category'],
                (float) $topCut['monthly']
            );
        } else {
            $lines[] = "- Structural move: lock one automatic weekly transfer for this goal.";
        }

        return implode("\n", $lines);
    }

    // ----------------------------
    // Dashboard (GET only)
    // ----------------------------

    #[Route('/savings', name: 'app_savings', methods: ['GET'])]
    #[Route('/savings/index', name: 'app_savings_index', methods: ['GET'])]
    public function index(
        Connection $conn,
        Security $security,
        Request $request,
        PaginatorInterface $paginator,
        SavingsGoalStatsService $savingsGoalStatsService,
        SavingsStatsService $savingsStatsService,
        ChartBuilderInterface $chartBuilder
    ): Response
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

        $goalsRaw = ($accId > 0) ? $conn->fetchAllAssociative(
            "SELECT id, nom, montant_cible, montant_actuel, date_limite, priorite
             FROM financial_goal
             $where
             ORDER BY $orderBy",
            $params
        ) : [];

        // ----------------------------
        // Stats (goals)
        // ----------------------------
        $balance = $currentAccount ? $this->safeFloat($currentAccount[$accBalanceCol] ?? 0) : 0.0;

        $activeGoals = 0;
        $avgProgress = 0.0;
        $nearestDeadline = null;

        $progressSum = 0.0;
        foreach ($goalsRaw as $g) {
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
        if (count($goalsRaw) > 0) $avgProgress = $progressSum / count($goalsRaw);

        $stats = [
            'balance' => $balance,
            'activeGoals' => $activeGoals,
            'avgProgress' => (int) round($avgProgress),
            'nearestDeadline' => $nearestDeadline ?? '--/--/----',
        ];

        $goalHealth = [
            'summary' => [
                'totalDailyNeeded' => 0.0,
                'monthlyNeeded' => 0.0,
                'coveragePct' => 100.0,
                'coverageRawPct' => 100.0,
                'feasibilityStatus' => 'stable',
                'goalPressureIndex' => 0.0,
                'recommendedMonthlyContribution' => 0.0,
                'recommendedWeeklyContribution' => 0.0,
                'criticalCount' => 0,
                'warningCount' => 0,
                'overdueCount' => 0,
            ],
            'topUrgent' => [],
            'alerts' => [],
            'recommendations' => [],
        ];

        if ($accId > 0 && count($goalsRaw) > 0) {
            $today = new \DateTimeImmutable('today');
            $goalRows = [];
            foreach ($goalsRaw as $row) {
                $target = max(0.0, $this->safeFloat($row['montant_cible'] ?? 0));
                $current = max(0.0, $this->safeFloat($row['montant_actuel'] ?? 0));
                $remaining = max(0.0, $target - $current);
                $progressRatio = $target > 0 ? min(1.0, $current / $target) : 0.0;

                $dateLimiteRaw = (string) ($row['date_limite'] ?? '');
                $daysLeft = 999999;
                if ($dateLimiteRaw !== '') {
                    try {
                        $goalDate = new \DateTimeImmutable(substr($dateLimiteRaw, 0, 10));
                        $daysLeft = (int) $today->diff($goalDate)->format('%r%a');
                    } catch (\Throwable $e) {
                        $daysLeft = 999999;
                    }
                }

                $dailyNeeded = 0.0;
                if ($daysLeft > 0 && $remaining > 0.0) {
                    $dailyNeeded = $remaining / $daysLeft;
                }
                $monthlyNeeded = $dailyNeeded * 30.0;

                $urgencyScore = ((int) ($row['priorite'] ?? 3) * 8)
                    + ((1.0 - $progressRatio) * 60)
                    + ($daysLeft <= 0 ? 40 : ($daysLeft <= 7 ? 30 : ($daysLeft <= 30 ? 15 : 5)));

                $goalRows[] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'nom' => (string) ($row['nom'] ?? 'Goal'),
                    'montantCible' => $target,
                    'montantActuel' => $current,
                    'priorite' => (int) ($row['priorite'] ?? 3),
                    'dateLimite' => $dateLimiteRaw !== '' ? $dateLimiteRaw : null,
                    'progressRatio' => $progressRatio,
                    'remainingAmount' => $remaining,
                    'daysLeft' => $daysLeft,
                    'dailyNeeded' => $dailyNeeded,
                    'monthlyNeeded' => $monthlyNeeded,
                    'urgencyScore' => $urgencyScore,
                ];
            }

            $goalHealth = $savingsGoalStatsService->buildGoalHealthDashboard($goalRows, $balance);
        }

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

        if ($tx_range === 'today') {
            $whereTx .= " AND DATE(`date`) = CURDATE() ";
        } elseif ($tx_range === 'week') {
            $whereTx .= " AND YEARWEEK(`date`, 1) = YEARWEEK(CURDATE(), 1) ";
        } elseif ($tx_range === 'month') {
            $whereTx .= " AND YEAR(`date`) = YEAR(CURDATE()) AND MONTH(`date`) = MONTH(CURDATE()) ";
        }

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

        $orderTx = match ($tx_sort) {
            'date_asc'     => "`date` ASC",
            'amount_desc'  => "montant DESC",
            'amount_asc'   => "montant ASC",
            'desc_asc'     => "description ASC",
            'desc_desc'    => "description DESC",
            default        => "`date` DESC",
        };

        $transactionsRaw = $conn->fetchAllAssociative(
            "SELECT id, type, montant, `date`, description
             FROM `transaction`
             $whereTx
             ORDER BY $orderTx",
            $paramsTx
        );

        $stat_by = (string) $request->query->get('stat_by', 'type');
        $statsPack = $savingsStatsService->build($transactionsRaw, $stat_by);
        $tx_stats = $statsPack['tx_stats'];
        $stat_by = $statsPack['stat_by'];
        $stat_labels = $statsPack['stat_labels'];
        $stat_values = $statsPack['stat_values'];

        $txPage = max(1, (int) $request->query->get('tx_page', 1));
        $goalPage = max(1, (int) $request->query->get('goal_page', 1));

        $transactions = $paginator->paginate(
            $transactionsRaw,
            $txPage,
            5,
            ['pageParameterName' => 'tx_page']
        );

        $goals = $paginator->paginate(
            $goalsRaw,
            $goalPage,
            3,
            ['pageParameterName' => 'goal_page']
        );

        $barChart = $chartBuilder->createChart(Chart::TYPE_BAR);
        $barChart->setData([
            'labels' => $stat_labels,
            'datasets' => [[
                'label' => 'Total (TND)',
                'data' => $stat_values,
                'backgroundColor' => 'rgba(14, 165, 233, 0.55)',
                'borderColor' => 'rgba(14, 165, 233, 1)',
                'borderWidth' => 1,
            ]],
        ]);
        $barChart->setOptions([
            'responsive' => true,
            'plugins' => ['legend' => ['display' => false]],
        ]);

        $pieChart = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $pieChart->setData([
            'labels' => $stat_labels,
            'datasets' => [[
                'data' => $stat_values,
                'backgroundColor' => [
                    '#0ea5e9', '#22c55e', '#f59e0b', '#ef4444', '#8b5cf6',
                    '#14b8a6', '#84cc16', '#f97316', '#06b6d4', '#a855f7',
                ],
            ]],
        ]);
        $pieChart->setOptions(['responsive' => true]);

        return $this->render('savings/index.html.twig', [
            'tab' => $tab,
            'stats' => $stats,
            'savingAccount' => $currentAccount ?? [],
            'accounts' => $accounts,
            'transactions' => $transactions,
            'goals' => $goals,
            'goal_health' => $goalHealth,
            'q' => $q,
            'sort' => $sort,
            'tx_q' => $tx_q,
            'tx_sort' => $tx_sort,
            'tx_range' => $tx_range,
            'tx_stats' => $tx_stats,
            'stat_by' => $stat_by,
            'stat_labels' => $stat_labels,
            'stat_values' => $stat_values,
            'bar_chart' => $barChart,
            'pie_chart' => $pieChart,
        ]);
    }

    #[Route('/savings/calendar/data', name: 'app_savings_calendar_data', methods: ['GET'])]
    public function calendarData(
        Connection $conn,
        Security $security,
        Request $request,
        SavingsCalendarService $savingsCalendarService
    ): JsonResponse {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $rawMonth = (string) $request->query->get('month', (new \DateTimeImmutable('now'))->format('Y-m'));
        if (!preg_match('/^\d{4}-\d{2}$/', $rawMonth)) {
            $rawMonth = (new \DateTimeImmutable('now'))->format('Y-m');
        }

        $country = strtoupper((string) $request->query->get('country', 'TN'));
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            $country = 'TN';
        }
        $currency = strtoupper((string) $request->query->get('currency', 'TND'));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $currency = 'TND';
        }

        $monthStart = \DateTimeImmutable::createFromFormat('Y-m-d', $rawMonth . '-01');
        if (!$monthStart instanceof \DateTimeImmutable) {
            $monthStart = new \DateTimeImmutable('first day of this month');
        }

        $monthEnd = $monthStart->modify('last day of this month');
        $daysInMonth = (int) $monthStart->format('t');
        $todayForInflation = new \DateTimeImmutable('today');
        $inflationSnapshot = $savingsCalendarService->fetchAnnualInflationSnapshot($country);
        $inflationSeries = is_array($inflationSnapshot['series'] ?? null) ? $inflationSnapshot['series'] : [];
        $projectionStartYear = (int) $todayForInflation->format('Y');
        $projectionEndYear = max((int) $monthEnd->format('Y') + 10, $projectionStartYear + 1);
        $inflationByYear = $savingsCalendarService->buildProjectedInflationByYear(
            $inflationSeries,
            $projectionStartYear,
            $projectionEndYear
        );
        $yearOfView = (int) $monthStart->format('Y');
        $annualInflationRate = (float) ($inflationByYear[$yearOfView] ?? (float) ($inflationSnapshot['rate'] ?? 0.0));
        $inflationByDate = $savingsCalendarService->buildDailyInflationMapForMonthUsingYearMap(
            $yearOfView,
            (int) $monthStart->format('m'),
            $inflationByYear,
            $annualInflationRate
        );

        $dayEvents = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dayEvents[(string) $d] = [];
        }

        $goals = [];
        if ((int) $accPack['accId'] > 0) {
            $goals = $conn->fetchAllAssociative(
                "SELECT id, nom, montant_cible, montant_actuel, date_limite, priorite
                 FROM financial_goal
                 WHERE `$goalAccCol` = :acc
                   AND date_limite IS NOT NULL
                   AND DATE(date_limite) BETWEEN :start AND :end
                 ORDER BY date_limite ASC",
                [
                    'acc' => (int) $accPack['accId'],
                    'start' => $monthStart->format('Y-m-d'),
                    'end' => $monthEnd->format('Y-m-d'),
                ]
            );
        }

        foreach ($goals as $g) {
            $date = (string) ($g['date_limite'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                continue;
            }

            $day = (int) substr($date, 8, 2);
            $target = (float) ($g['montant_cible'] ?? 0);
            $current = (float) ($g['montant_actuel'] ?? 0);
            $progressPct = $target > 0 ? min(100.0, ($current / $target) * 100.0) : 0.0;
            $deadlineDt = \DateTimeImmutable::createFromFormat('Y-m-d', substr($date, 0, 10)) ?: null;
            $adjustedTarget = $target;
            $deadlineRate = $annualInflationRate;
            if ($deadlineDt instanceof \DateTimeImmutable) {
                $deadlineRate = (float) ($inflationByYear[(int) $deadlineDt->format('Y')] ?? $annualInflationRate);
                $adjustedTarget = $savingsCalendarService->adjustAmountForInflationByYear(
                    $target,
                    $todayForInflation,
                    $deadlineDt,
                    $inflationByYear,
                    $annualInflationRate
                );
            }
            $inflationDelta = max(0.0, $adjustedTarget - $target);

            $dayEvents[(string) $day][] = [
                'kind' => 'goal_deadline',
                'title' => 'Goal deadline: ' . (string) ($g['nom'] ?? 'Goal'),
                'amount' => $target,
                'adjustedTarget' => round($adjustedTarget, 2),
                'inflationDelta' => round($inflationDelta, 2),
                'inflationRate' => round($deadlineRate, 4),
                'progressPct' => round($progressPct, 1),
                'priority' => (int) ($g['priorite'] ?? 3),
            ];
        }

        $transactions = $conn->fetchAllAssociative(
            "SELECT type, montant, `date`, description
             FROM `transaction`
             WHERE user_id = :uid
               AND module_source = :src
               AND DATE(`date`) BETWEEN :start AND :end
             ORDER BY `date` ASC",
            [
                'uid' => $userId,
                'src' => 'SAVINGS',
                'start' => $monthStart->format('Y-m-d'),
                'end' => $monthEnd->format('Y-m-d'),
            ]
        );

        foreach ($transactions as $tx) {
            $date = (string) ($tx['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                continue;
            }

            $day = (int) substr($date, 8, 2);
            $type = strtoupper((string) ($tx['type'] ?? ''));
            $kind = match ($type) {
                'EPARGNE' => 'deposit',
                'GOAL_CONTRIB' => 'goal_contribution',
                'GOAL_REFUND' => 'goal_refund',
                default => 'transaction',
            };

            $label = match ($kind) {
                'deposit' => 'Deposit',
                'goal_contribution' => 'Goal contribution',
                'goal_refund' => 'Goal refund',
                default => 'Transaction',
            };

            $dayEvents[(string) $day][] = [
                'kind' => $kind,
                'title' => $label . ': ' . (string) ($tx['description'] ?: 'No description'),
                'amount' => (float) ($tx['montant'] ?? 0),
            ];
        }

        $holidays = $savingsCalendarService->fetchPublicHolidays((int) $monthStart->format('Y'), $country);
        $holidaySet = [];
        foreach ($holidays as $h) {
            $date = (string) ($h['date'] ?? '');
            if (substr($date, 0, 7) !== $monthStart->format('Y-m')) {
                continue;
            }
            $holidaySet[$date] = true;
            $day = (int) substr($date, 8, 2);
            $dayEvents[(string) $day][] = [
                'kind' => 'holiday',
                'title' => 'Holiday: ' . (string) ($h['localName'] ?: $h['name']),
            ];
        }

        // ----------------------------
        // Adaptive plan (API-powered)
        // Keeps existing dayEvents intact and adds "plan_suggestion" events
        // ----------------------------
        $balance = $this->safeFloat(($accPack['currentAccount'][$accPack['accBalanceCol']] ?? 0));
        $fxByDate = $savingsCalendarService->fetchExchangeRateMapToTndForMonth(
            $currency,
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('m')
        );
        $fxToTnd = $savingsCalendarService->fetchExchangeRateToTnd($currency);
        if ($fxToTnd <= 0.0 && !empty($fxByDate)) {
            $candidate = reset($fxByDate);
            $fxToTnd = is_float($candidate) || is_int($candidate) ? (float) $candidate : 0.0;
        }
        if ($currency === 'TND' && $fxToTnd <= 0.0) {
            $fxToTnd = 1.0;
        }

        $today = new \DateTimeImmutable('today');
        $startForPlan = $today > $monthStart ? $today : $monthStart;

        $businessDaysLeft = 0;
        for ($d = $startForPlan; $d <= $monthEnd; $d = $d->modify('+1 day')) {
            $iso = (int) $d->format('N');
            $dateKey = $d->format('Y-m-d');
            if ($iso >= 6 || isset($holidaySet[$dateKey])) {
                continue;
            }
            $businessDaysLeft++;
        }
        if ($businessDaysLeft <= 0) {
            $businessDaysLeft = 1;
        }

        $remainingTotal = 0.0;
        foreach ($goals as $g) {
            $target = $this->safeFloat($g['montant_cible'] ?? 0);
            $current = $this->safeFloat($g['montant_actuel'] ?? 0);
            $remaining = max(0.0, $target - $current);
            $remainingTotal += $remaining;
        }

        $baseMonthlyNeed = $remainingTotal;
        $baseWeeklyNeed = $baseMonthlyNeed / 4.0;

        $inflationMode = 'balanced';
        $inflationFactor = 1.0;
        if ($annualInflationRate <= 0.0) {
            $inflationMode = 'unavailable';
            $inflationFactor = 1.0;
        } elseif ($annualInflationRate >= 0.08) {
            $inflationMode = 'inflation_guard';
            $inflationFactor = 1.12;
        } elseif ($annualInflationRate <= 0.03) {
            $inflationMode = 'stable_prices';
            $inflationFactor = 0.96;
        }

        $monthlyRecommendedTnd = max(0.0, $baseMonthlyNeed * $inflationFactor);
        $weeklyRecommendedTnd = $monthlyRecommendedTnd / 4.0;

        $coverage = $monthlyRecommendedTnd > 0 ? ($balance / $monthlyRecommendedTnd) : 1.0;
        $feasibility = $coverage < 0.5 ? 'critical' : ($coverage < 1.0 ? 'warning' : 'on_track');

        // Suggest weekly contribution days (Mondays), shifted to next business day when needed.
        $suggestionDates = [];
        if ($monthlyRecommendedTnd > 0) {
            $cursor = $startForPlan;
            while ($cursor <= $monthEnd) {
                if ((int) $cursor->format('N') === 1) {
                    $probe = $cursor;
                    while ($probe <= $monthEnd) {
                        $probeIso = (int) $probe->format('N');
                        $probeKey = $probe->format('Y-m-d');
                        if ($probeIso < 6 && !isset($holidaySet[$probeKey])) {
                            $suggestionDates[$probeKey] = true;
                            break;
                        }
                        $probe = $probe->modify('+1 day');
                    }
                }
                $cursor = $cursor->modify('+1 day');
            }

            if (empty($suggestionDates)) {
                $probe = $startForPlan;
                while ($probe <= $monthEnd) {
                    $probeIso = (int) $probe->format('N');
                    $probeKey = $probe->format('Y-m-d');
                    if ($probeIso < 6 && !isset($holidaySet[$probeKey])) {
                        $suggestionDates[$probeKey] = true;
                        break;
                    }
                    $probe = $probe->modify('+1 day');
                }
            }
        }

        $suggestionRows = [];
        $suggestionCount = max(1, count($suggestionDates));
        $perSuggestionTnd = $monthlyRecommendedTnd / $suggestionCount;
        foreach (array_keys($suggestionDates) as $dateKey) {
            if (substr($dateKey, 0, 7) !== $monthStart->format('Y-m')) {
                continue;
            }
            $day = (int) substr($dateKey, 8, 2);
            $inflationRate = (float) ($inflationByDate[$dateKey] ?? $annualInflationRate);
            $fxForDate = (float) ($fxByDate[$dateKey] ?? $fxToTnd);
            $displayAmount = $currency === 'TND'
                ? $perSuggestionTnd
                : ($fxForDate > 0 ? ($perSuggestionTnd / $fxForDate) : $perSuggestionTnd);

            $dayEvents[(string) $day][] = [
                'kind' => 'plan_suggestion',
                'title' => sprintf(
                    'Suggested contribution (%s mode): %.2f %s',
                    $inflationMode,
                    $displayAmount,
                    $currency
                ),
                'amount' => round($perSuggestionTnd, 2),
                'amountCurrency' => $currency,
                'amountConverted' => round($displayAmount, 2),
                'inflationRate' => round($inflationRate, 4),
                'fxRateToTnd' => round($fxForDate, 4),
            ];

            $suggestionRows[] = [
                'date' => $dateKey,
                'amountTnd' => round($perSuggestionTnd, 2),
                'amount' => round($displayAmount, 2),
                'currency' => $currency,
                'inflationRate' => round($inflationRate, 4),
            ];
        }

        $counts = [
            'deadlines' => 0,
            'deposits' => 0,
            'contributions' => 0,
            'holidays' => 0,
            'plans' => 0,
        ];
        foreach ($dayEvents as $events) {
            foreach ($events as $evt) {
                $counts['deadlines'] += ($evt['kind'] ?? '') === 'goal_deadline' ? 1 : 0;
                $counts['deposits'] += ($evt['kind'] ?? '') === 'deposit' ? 1 : 0;
                $counts['contributions'] += ($evt['kind'] ?? '') === 'goal_contribution' ? 1 : 0;
                $counts['holidays'] += ($evt['kind'] ?? '') === 'holiday' ? 1 : 0;
                $counts['plans'] += ($evt['kind'] ?? '') === 'plan_suggestion' ? 1 : 0;
            }
        }

        return new JsonResponse([
            'ok' => true,
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'firstDayIso' => (int) $monthStart->format('N'),
            'daysInMonth' => $daysInMonth,
            'dayEvents' => $dayEvents,
            'inflationByDate' => $inflationByDate,
            'fxByDate' => $fxByDate,
            'summary' => $counts,
            'country' => $country,
            'fxDataQuality' => [
                'isFallback' => $currency !== 'TND' && empty($fxByDate),
                'source' => 'fawazahmed0/currency-api daily snapshots; fallback frankfurter latest',
            ],
            'inflationDataQuality' => [
                'isFallback' => (bool) ($inflationSnapshot['isFallback'] ?? true),
                'source' => (string) ($inflationSnapshot['source'] ?? 'unknown'),
                'year' => $inflationSnapshot['year'] ?? null,
                'error' => $inflationSnapshot['error'] ?? null,
                'seriesYears' => array_slice(array_values(array_keys($inflationSeries)), -8),
            ],
            'adaptivePlan' => [
                'inflationMode' => $inflationMode,
                'inflationAnnualRate' => round($annualInflationRate, 4),
                'cpiIndicator' => 'CPI',
                'inflationYearOfView' => $yearOfView,
                'inflationYear' => $inflationSnapshot['year'] ?? null,
                'inflationSource' => (string) ($inflationSnapshot['source'] ?? 'unknown'),
                'inflationIsFallback' => (bool) ($inflationSnapshot['isFallback'] ?? true),
                'currency' => $currency,
                'fxRateToTnd' => round($fxToTnd, 4),
                'monthlyRecommendedTnd' => round($monthlyRecommendedTnd, 2),
                'weeklyRecommendedTnd' => round($weeklyRecommendedTnd, 2),
                'feasibility' => $feasibility,
                'coverageRatio' => round($coverage, 2),
                'suggestions' => $suggestionRows,
            ],
        ]);
    }

    // -------------------------------------------------
    // Routes (forms should point here)
    // -------------------------------------------------

    #[Route('/savings/deposit', name: 'app_savings_deposit', methods: ['POST'])]
    public function deposit(Connection $conn, Security $security, Request $request, ValidatorInterface $validator): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);

        $amount = $this->safeFloat($request->request->get('amount'));
        $desc   = trim((string) $request->request->get('description', ''));

        $errs = $this->validateDeposit($amount, $validator);

        // AJAX => JSON
        if ($request->isXmlHttpRequest()) {
            if ($errs) {
                $msg = implode(' ', $errs);
                return new JsonResponse([
                    'ok' => false,
                    'type' => 'danger',
                    'title' => 'Montant invalide',
                    'message' => $msg !== '' ? $msg : "Le dépôt doit être strictement positif (ex: 20.00 TND).",
                    'code' => 'DEP-VAL-001'
                ], 422);
            }

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

            $accId         = (int) $accPack['accId'];
            $accPkCol      = (string) $accPack['accPkCol'];
            $accUserCol    = (string) $accPack['accUserCol'];
            $accBalanceCol = (string) $accPack['accBalanceCol'];

            $newBalance = (float) $conn->fetchOne(
                "SELECT `$accBalanceCol`
                 FROM saving_account
                 WHERE `$accPkCol` = :id AND `$accUserCol` = :uid LIMIT 1",
                ['id' => $accId, 'uid' => $userId]
            );

            return new JsonResponse([
                'ok' => true,
                'type' => 'success',
                'title' => 'Dépôt enregistré',
                'message' => 'Votre dépôt a été ajouté avec succès.',
                'newBalance' => $newBalance,
                'code' => 'DEP-OK-200'
            ]);
        }

        // normal submit => redirect + flash
        if ($errs) {
            $this->flashErrors($errs);
            return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
        }

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

        $this->addFlash('success', '✅ Dépôt effectué avec succès.');
        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }

    #[Route('/savings/goal/new', name: 'app_goal_new', methods: ['POST'])]
    public function goalNew(Connection $conn, Security $security, Request $request, ValidatorInterface $validator): Response
    {
        $userId  = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $nom      = trim((string) $request->request->get('nom'));
        $target   = $this->safeFloat($request->request->get('montant_cible'));
        $current  = $this->safeFloat($request->request->get('montant_actuel'));
        $dateRaw  = (string) $request->request->get('date_limite', '');
        $priorite = $this->safeInt($request->request->get('priorite'), 3);

        $dateLimite = $this->parseYmd($dateRaw);

        $errs = $this->validateGoalInputs($nom, $target, $current, $dateLimite, $priorite, $validator);
        if ($request->isXmlHttpRequest()) {
            if ($errs) {
                return new JsonResponse([
                    'ok' => false,
                    'type' => 'danger',
                    'title' => 'Invalid goal',
                    'message' => implode(' ', $errs),
                    'errors' => $errs,
                    'code' => 'GOAL-VAL-001',
                ], 422);
            }

            $this->doAddGoal(
                $conn,
                (int) $accPack['accId'],
                $goalAccCol,
                $nom,
                $target,
                $current,
                (string)($dateLimite ?? ''),
                $priorite
            );

            return new JsonResponse([
                'ok' => true,
                'type' => 'success',
                'title' => 'Goal added',
                'message' => 'Your goal was added successfully.',
                'refresh' => 'goals',
                'code' => 'GOAL-OK-200',
            ]);
        }

        if ($errs) {
            $this->flashErrors($errs);
            return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
        }

        $this->doAddGoal(
            $conn,
            (int) $accPack['accId'],
            $goalAccCol,
            $nom,
            $target,
            $current,
            (string)($dateLimite ?? ''),
            $priorite
        );

        $this->addFlash('success', '✅ Goal ajouté avec succès.');
        return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
    }

    #[Route('/savings/goal/{id}/edit', name: 'app_goal_edit', methods: ['POST'])]
    public function goalEdit(Connection $conn, Security $security, Request $request, int $id, ValidatorInterface $validator): Response
    {
        $userId  = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $nom      = trim((string) $request->request->get('nom', ''));
        $target   = $this->safeFloat($request->request->get('montant_cible'));
        $dateRaw  = (string) $request->request->get('date_limite', '');
        $priorite = $this->safeInt($request->request->get('priorite'), 3);

        $dateLimite = $this->parseYmd($dateRaw);

        $errs = $this->validateGoalInputs($nom, $target, 0, $dateLimite, $priorite, $validator);
        if ($errs) {
            $this->flashErrors($errs);
            return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
        }

        $this->doEditGoal(
            $conn,
            $userId,
            (int) $accPack['accId'],
            $goalAccCol,
            (string) $accPack['accUserCol'],
            (string) $accPack['accBalanceCol'],
            (string) $accPack['accPkCol'],
            $id,
            $nom,
            $target,
            (string)($dateLimite ?? ''),
            $priorite
        );

        $this->addFlash('success', '✅ Goal modifié avec succès.');
        return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
    }

    #[Route('/savings/goal/{id}/delete', name: 'app_goal_delete', methods: ['POST'])]
    public function goalDelete(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId  = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        if ($id <= 0) {
            $this->addFlash('danger', '❌ Goal invalide.');
            return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
        }

        $this->doDeleteGoal($conn, (int) $accPack['accId'], $goalAccCol, $id);

        $this->addFlash('success', '✅ Goal supprimé.');
        return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
    }

    #[Route('/savings/goal/{id}/contribute', name: 'app_goal_contribute', methods: ['POST'])]
    public function goalContribute(Connection $conn, Security $security, Request $request, int $id, ValidatorInterface $validator): Response
    {
        $userId  = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $add = $this->safeFloat($request->request->get('add_amount'));

        $errs = $this->validateContribution($add, $validator);
        if ($request->isXmlHttpRequest()) {
            if ($errs) {
                return new JsonResponse([
                    'ok' => false,
                    'type' => 'danger',
                    'title' => 'Invalid contribution',
                    'message' => implode(' ', $errs),
                    'errors' => $errs,
                    'code' => 'CONTRIB-VAL-001',
                ], 422);
            }

            $before = $conn->fetchOne(
                "SELECT montant_actuel FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
                ['gid' => $id, 'acc' => (int) $accPack['accId']]
            );

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

            $after = $conn->fetchOne(
                "SELECT montant_actuel FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
                ['gid' => $id, 'acc' => (int) $accPack['accId']]
            );

            if ($before !== null && $after !== null && (float) $after === (float) $before) {
                return new JsonResponse([
                    'ok' => false,
                    'type' => 'danger',
                    'title' => 'Contribution refused',
                    'message' => 'Insufficient savings balance or invalid goal.',
                    'code' => 'CONTRIB-REFUSED-001',
                ], 422);
            }

            $newBalance = (float) $conn->fetchOne(
                "SELECT `{$accPack['accBalanceCol']}` FROM saving_account
                 WHERE `{$accPack['accPkCol']}` = :id AND `{$accPack['accUserCol']}` = :uid LIMIT 1",
                ['id' => (int) $accPack['accId'], 'uid' => $userId]
            );

            return new JsonResponse([
                'ok' => true,
                'type' => 'success',
                'title' => 'Contribution added',
                'message' => 'Your contribution was added successfully.',
                'newBalance' => $newBalance,
                'refresh' => 'goals',
                'code' => 'CONTRIB-OK-200',
            ]);
        }

        if ($errs) {
            $this->flashErrors($errs);
            return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
        }

        // detect if contributed (simple check)
        $before = $conn->fetchOne(
            "SELECT montant_actuel FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
            ['gid' => $id, 'acc' => (int) $accPack['accId']]
        );

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

        $after = $conn->fetchOne(
            "SELECT montant_actuel FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
            ['gid' => $id, 'acc' => (int) $accPack['accId']]
        );

        if ($before !== null && $after !== null && (float) $after == (float) $before) {
            $this->addFlash('danger', '❌ Contribution refusée (solde insuffisant ou goal invalide).');
        } else {
            $this->addFlash('success', '✅ Contribution ajoutée.');
        }

        return $this->redirectToRoute('app_savings_index', ['tab' => 'goals']);
    }

    #[Route('/savings/rate/update', name: 'app_savings_rate_update', methods: ['POST'])]
    public function updateRate(Connection $conn, Security $security, Request $request, ValidatorInterface $validator): Response
    {
        $userId  = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);

        $accId      = (int) $accPack['accId'];
        $accPkCol   = (string) $accPack['accPkCol'];
        $accUserCol = (string) $accPack['accUserCol'];

        $rate = $this->safeFloat($request->request->get('taux_interet'));

        $errs = $this->validateRate($rate, $validator);
        if ($errs) {
            $this->flashErrors($errs);
            return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
        }

        $rate = max(0, min(100, $rate));

        $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `saving_account`");
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
            $this->addFlash('success', '✅ Taux mis à jour.');
        } else {
            $this->addFlash('danger', '❌ Colonne taux_interet introuvable ou compte invalide.');
        }

        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }

    // Optional: transaction edit/delete routes (if your Twig has buttons)
    #[Route('/savings/tx/{id}/delete', name: 'app_savings_tx_delete', methods: ['POST'])]
    public function txDelete(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $this->doDeleteTransaction($conn, $userId, $id);
        $this->addFlash('success', '✅ Transaction supprimée.');
        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }

    #[Route('/savings/tx/{id}/edit', name: 'app_savings_tx_edit', methods: ['POST'])]
    public function txEdit(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);

        $amount = $this->safeFloat($request->request->get('montant'));
        $desc   = trim((string) $request->request->get('description', ''));
        $dateRaw = $request->request->get('date'); // datetime-local optional

        if ($amount <= 0) {
            $this->addFlash('danger', '❌ Montant invalide.');
            return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
        }

        $this->doEditTransaction($conn, $userId, $id, $amount, $desc, is_string($dateRaw) ? $dateRaw : null);
        $this->addFlash('success', '✅ Transaction modifiée.');
        return $this->redirectToRoute('app_savings_index', ['tab' => 'savings']);
    }

    #[Route('/savings/export/csv', name: 'app_savings_export_csv', methods: ['GET'])]
    public function exportCsv(
        Connection $conn,
        Security $security,
        Request $request,
        CsvGeneratorInterface $csvGenerator
    ): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);

        $q     = trim((string) $request->query->get('tx_q', ''));
        $sort  = (string) $request->query->get('tx_sort', 'date_desc');
        $range = (string) $request->query->get('tx_range', 'all');

        $dateWhere = '';
        $params = ['uid' => $userId, 'src' => 'SAVINGS'];

        if ($range === 'today') {
            $dateWhere = " AND DATE(`date`) = CURDATE() ";
        } elseif ($range === 'week') {
            $dateWhere = " AND YEARWEEK(`date`, 1) = YEARWEEK(CURDATE(), 1) ";
        } elseif ($range === 'month') {
            $dateWhere = " AND YEAR(`date`) = YEAR(CURDATE()) AND MONTH(`date`) = MONTH(CURDATE()) ";
        }

        $searchWhere = '';
        if ($q !== '') {
            $searchWhere = " AND (
                CAST(id AS CHAR) LIKE :q OR
                type LIKE :q OR
                CAST(montant AS CHAR) LIKE :q OR
                CAST(`date` AS CHAR) LIKE :q OR
                description LIKE :q
            )";
            $params['q'] = '%' . $q . '%';
        }

        $orderBy = match ($sort) {
            'date_asc' => '`date` ASC',
            'amount_desc' => 'montant DESC',
            'amount_asc' => 'montant ASC',
            'desc_asc' => 'description ASC',
            'desc_desc' => 'description DESC',
            default => '`date` DESC',
        };

        $rows = $conn->fetchAllAssociative(
            "SELECT id, type, montant, `date`, description
             FROM `transaction`
             WHERE user_id = :uid AND module_source = :src
             $dateWhere
             $searchWhere
             ORDER BY $orderBy",
            $params
        );

        $objects = array_map(static fn(array $r): SavingsTransactionCsvRow => new SavingsTransactionCsvRow(
            (int) ($r['id'] ?? 0),
            (string) ($r['type'] ?? ''),
            number_format((float) ($r['montant'] ?? 0), 2, '.', ''),
            (string) ($r['date'] ?? ''),
            (string) ($r['description'] ?? '')
        ), $rows);

        $configuration = CsvGeneratorConfiguration::create(SavingsTransactionCsvRow::class, $objects)
            ->setDelimiter(',')
            ->setWithHeader(true);

        return $csvGenerator->streamResponse($configuration, 'savings_transactions');
    }
    #[Route('/savings/what-if', name: 'app_savings_what_if', methods: ['POST'])]
    public function whatIf(
        Connection $conn,
        Security $security,
        Request $request,
        GoalWhatIfService $goalWhatIfService,
        GoalWhatIfAdvisor $goalWhatIfAdvisor,
        WhatIfAiNarratorService $whatIfAiNarratorService
    ): JsonResponse {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $selectedGoalId = max(0, $this->safeInt($payload['selected_goal_id'] ?? ($payload['selectedGoalId'] ?? 0)));
        $scenarioType = strtolower(trim((string) ($payload['scenario_type'] ?? ($payload['scenarioType'] ?? 'deposit_adjustment'))));
        if (!in_array($scenarioType, ['deposit_adjustment', 'deadline_adjustment', 'target_adjustment'], true)) {
            $scenarioType = 'deposit_adjustment';
        }

        $monthlyDeposit = max(0.0, $this->safeFloat($payload['monthly_deposit'] ?? ($payload['monthlyDeposit'] ?? 0)));
        $oneTimeDeposit = max(0.0, $this->safeFloat($payload['one_time_deposit'] ?? ($payload['oneTimeDeposit'] ?? 0)));
        $deadlineAdjustmentMonths = (int) max(-24, min(24, $this->safeInt($payload['deadline_adjustment_months'] ?? ($payload['deadlineAdjustmentMonths'] ?? 0))));
        $targetAdjustmentAmount = (float) max(-1000000.0, min(1000000.0, $this->safeFloat($payload['target_adjustment_amount'] ?? ($payload['targetAdjustmentAmount'] ?? 0))));
        $baselineMonthlyDeposit = max(0.0, $this->safeFloat($payload['baseline_monthly_deposit'] ?? ($payload['baselineMonthlyDeposit'] ?? $monthlyDeposit)));

        if ((int) $accPack['accId'] <= 0) {
            return new JsonResponse(['ok' => false, 'message' => 'No savings account found.'], 422);
        }

        $goal = $conn->fetchAssociative(
            "SELECT id, nom, montant_cible, montant_actuel, date_limite
             FROM financial_goal
             WHERE id = :gid AND `$goalAccCol` = :acc
             LIMIT 1",
            ['gid' => $selectedGoalId, 'acc' => (int) $accPack['accId']]
        );
        if (!$goal) {
            return new JsonResponse(['ok' => false, 'message' => 'Selected goal not found.'], 422);
        }

        $goalName = (string) ($goal['nom'] ?? 'Goal');
        $currentSaved = max(0.0, $this->safeFloat($goal['montant_actuel'] ?? 0));
        $baseTarget = max(0.0, $this->safeFloat($goal['montant_cible'] ?? 0));
        $baseDeadline = isset($goal['date_limite']) && (string) $goal['date_limite'] !== '' ? (string) $goal['date_limite'] : null;
        $todayDate = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $scenarioTarget = $baseTarget;
        $scenarioDeadline = $baseDeadline;
        $scenarioNotes = [];

        if ($scenarioType === 'deadline_adjustment' && $baseDeadline !== null) {
            try {
                $scenarioDeadline = (new \DateTimeImmutable($baseDeadline))
                    ->modify(($deadlineAdjustmentMonths >= 0 ? '+' : '') . $deadlineAdjustmentMonths . ' month')
                    ->format('Y-m-d');
                $scenarioNotes[] = sprintf('Deadline adjusted by %d month(s).', $deadlineAdjustmentMonths);
            } catch (\Throwable $e) {
                $scenarioNotes[] = 'Deadline adjustment skipped (invalid deadline date).';
            }
        } elseif ($scenarioType === 'target_adjustment') {
            $scenarioTarget = max($currentSaved, round($baseTarget + $targetAdjustmentAmount, 2));
            $scenarioNotes[] = sprintf('Target adjusted by %+.2f TND.', $targetAdjustmentAmount);
        } else {
            $scenarioNotes[] = 'Deposit adjustment scenario applied.';
        }

        $baselineMetrics = $goalWhatIfService->simulate([
            'current_saved' => $currentSaved,
            'target_amount' => $baseTarget,
            'deadline_date' => $baseDeadline,
            'today_date' => $todayDate,
            'monthly_deposit' => $baselineMonthlyDeposit,
            'one_time_deposit' => 0.0,
        ]);

        $metrics = $goalWhatIfService->simulate([
            'current_saved' => $currentSaved,
            'target_amount' => $scenarioTarget,
            'deadline_date' => $scenarioDeadline,
            'today_date' => $todayDate,
            'monthly_deposit' => $monthlyDeposit,
            'one_time_deposit' => $oneTimeDeposit,
        ]);

        $baselineMonths = $baselineMetrics['months_to_finish'];
        $scenarioMonths = $metrics['months_to_finish'];
        $deltaMonths = null;
        if (is_int($baselineMonths) && is_int($scenarioMonths)) {
            $deltaMonths = $baselineMonths - $scenarioMonths;
        }

        $requiredAdjustment = round((float) ($metrics['required_monthly_to_hit_deadline'] ?? 0.0) - $monthlyDeposit, 2);
        $metrics['required_adjustment_tnd'] = $requiredAdjustment;
        $metrics['delta_months'] = $deltaMonths;
        $metrics['delta_required_adjustment_tnd'] = round((float) ($metrics['required_monthly_to_hit_deadline'] ?? 0.0) - (float) ($baselineMetrics['required_monthly_to_hit_deadline'] ?? 0.0), 2);
        $metrics['goal_name'] = $goalName;
        $metrics['target_amount'] = $scenarioTarget;
        $metrics['current_saved'] = $currentSaved;
        $metrics['deadline_date'] = $scenarioDeadline;

        $fallbackAdvice = $goalWhatIfAdvisor->build($metrics, [
            'goal_name' => $goalName,
            'today_date' => $todayDate,
            'current_saved' => $currentSaved,
            'target_amount' => $scenarioTarget,
            'deadline_date' => $scenarioDeadline,
            'monthly_deposit' => $monthlyDeposit,
            'one_time_deposit' => $oneTimeDeposit,
            'scenario_type' => $scenarioType,
        ]);
        $aiInput = [
            'goal_name' => $goalName,
            'currency' => 'TND',
            'scenario_type' => match ($scenarioType) {
                'deadline_adjustment' => 'Deadline Adjustment',
                'target_adjustment' => 'Target Adjustment',
                default => 'Deposit Adjustment',
            },
            'metrics' => [
                'projected_finish_date' => $metrics['projected_finish_date'] ?? null,
                'risk_level' => $metrics['risk_level'] ?? 'HIGH',
                'feasibility_score' => (float) ($metrics['feasibility_score'] ?? 0.0),
                'deadline_confidence' => (float) ($metrics['deadline_confidence'] ?? 0.0),
                'deadline_gap_months' => (int) ($metrics['deadline_gap_months'] ?? 0),
                'required_monthly_to_hit_deadline' => (float) ($metrics['required_monthly_to_hit_deadline'] ?? 0.0),
                'current_monthly' => (float) $monthlyDeposit,
                'one_time_deposit' => (float) $oneTimeDeposit,
                'remaining_amount' => (float) ($metrics['remaining_after_now'] ?? 0.0),
                'delta_months' => $deltaMonths,
                'required_adjustment_safe' => (float) $requiredAdjustment,
            ],
        ];
        $cacheKey = implode('|', [
            'goal:' . $selectedGoalId,
            'scenario:' . $scenarioType,
            'monthly:' . round($monthlyDeposit, 2),
            'one_time:' . round($oneTimeDeposit, 2),
            'deadline_adj:' . $deadlineAdjustmentMonths,
            'target_adj:' . round($targetAdjustmentAmount, 2),
            'target:' . round($scenarioTarget, 2),
            'deadline:' . (string) $scenarioDeadline,
            'remaining:' . round((float) ($metrics['remaining_after_now'] ?? 0.0), 2),
            'gap:' . (int) ($metrics['deadline_gap_months'] ?? 0),
        ]);
        $aiNarration = $whatIfAiNarratorService->narrate($aiInput, $cacheKey);
        $advice = (($aiNarration['ok'] ?? false) === true && is_array($aiNarration['advice'] ?? null))
            ? $aiNarration['advice']
            : $fallbackAdvice;
        $options = [
            $advice['best_action'] ?? ['title' => 'Best action', 'details' => 'No suggestion.'],
            ...array_slice(is_array($advice['alternatives'] ?? null) ? $advice['alternatives'] : [], 0, 2),
        ];

        return new JsonResponse([
            'ok' => true,
            'scenario' => [
                'type' => $scenarioType,
                'selected_goal_id' => $selectedGoalId,
                'selected_goal_name' => $goalName,
                'monthly_deposit' => round($monthlyDeposit, 2),
                'one_time_deposit' => round($oneTimeDeposit, 2),
                'deadline_adjustment_months' => $deadlineAdjustmentMonths,
                'target_adjustment_amount' => round($targetAdjustmentAmount, 2),
                'notes' => $scenarioNotes,
            ],
            'metrics' => $metrics,
            'impact_summary' => [
                'delta_months' => $deltaMonths,
                'required_adjustment_tnd' => $requiredAdjustment,
            ],
            'advice' => $advice,
            'options' => $options,
            'advice_source' => ($aiNarration['ok'] ?? false) === true ? 'openai' : 'fallback',
            'advice_model' => $aiNarration['model'] ?? null,
            'advice_error' => $aiNarration['error'] ?? null,
            'details' => [
                'baseline' => $baselineMetrics,
                'scenario' => $metrics,
            ],
        ]);
    }
    #[Route('/savings/assistant/suggestions', name: 'app_savings_assistant_suggestions', methods: ['GET'])]
    public function assistantSuggestions(
        Connection $conn,
        Security $security,
        Request $request,
        SavingsAssistantService $assistantService
    ): JsonResponse {
        $suggestions = $assistantService->buildSuggestions([]);

        return new JsonResponse([
            'ok' => true,
            'suggestions' => $suggestions,
        ]);
    }

    #[Route('/savings/assistant/chat', name: 'app_savings_assistant_chat', methods: ['POST'])]
    public function assistantChat(
        Connection $conn,
        Security $security,
        Request $request,
        SavingsAssistantService $assistantService
    ): JsonResponse {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $message = trim((string) ($payload['message'] ?? ''));
        $history = $payload['history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        if ($message === '') {
            return new JsonResponse([
                'ok' => false,
                'message' => 'Message cannot be empty.',
            ], 422);
        }

        $userId = $this->resolveUserId($conn, $security, $request);
        $accPack = $this->getOrCreateCurrentAccount($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);
        $session = $request->getSession();
        $state = [];
        if ($session && $session->has('savings_assistant_state')) {
            $raw = $session->get('savings_assistant_state');
            if (is_array($raw)) {
                $state = $raw;
            }
        }

        $result = $assistantService->handleSavingsGoalsCommand(
            $conn,
            $userId,
            $accPack,
            $goalAccCol,
            $message,
            $history,
            $state
        );

        if ($session) {
            $session->set('savings_assistant_state', is_array($result['state'] ?? null) ? $result['state'] : []);
        }

        $ctx = $assistantService->buildSavingsGoalsSnapshot($conn, $userId, $accPack, $goalAccCol);

        return new JsonResponse([
            'ok' => (bool) ($result['ok'] ?? false),
            'reply' => (string) ($result['reply'] ?? ''),
            'source' => (string) ($result['source'] ?? 'fallback'),
            'model' => $result['model'] ?? null,
            'error' => $result['error'] ?? null,
            'dbContext' => [
                'balanceNow' => $ctx['balanceNow'] ?? 0,
                'goalsAtRisk' => $ctx['goalsAtRisk'] ?? 0,
            ],
        ], (bool) ($result['ok'] ?? false) ? 200 : 422);
    }

    #[Route('/savings/export/pdf', name: 'app_savings_export_pdf', methods: ['GET'])]
    public function exportPdf(Connection $conn, Security $security, Request $request, SavingsPdfService $savingsPdfService): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);

        $q     = trim((string) $request->query->get('tx_q', ''));
        $sort  = (string) $request->query->get('tx_sort', 'date_desc');
        $range = (string) $request->query->get('tx_range', 'all');

        $dateWhere = '';
        $params = ['uid' => $userId, 'src' => 'SAVINGS'];

        if ($range === 'today') {
            $dateWhere = " AND DATE(`date`) = CURDATE() ";
        } elseif ($range === 'week') {
            $dateWhere = " AND YEARWEEK(`date`, 1) = YEARWEEK(CURDATE(), 1) ";
        } elseif ($range === 'month') {
            $dateWhere = " AND YEAR(`date`) = YEAR(CURDATE()) AND MONTH(`date`) = MONTH(CURDATE()) ";
        }

        $searchWhere = '';
        if ($q !== '') {
            $searchWhere = " AND (
                CAST(id AS CHAR) LIKE :q OR
                type LIKE :q OR
                CAST(montant AS CHAR) LIKE :q OR
                CAST(`date` AS CHAR) LIKE :q OR
                description LIKE :q
            )";
            $params['q'] = '%' . $q . '%';
        }

        $orderBy = match ($sort) {
            'date_asc' => '`date` ASC',
            'amount_desc' => 'montant DESC',
            'amount_asc' => 'montant ASC',
            'desc_asc' => 'description ASC',
            'desc_desc' => 'description DESC',
            default => '`date` DESC',
        };

        $rows = $conn->fetchAllAssociative(
            "SELECT id, type, montant, `date`, description
             FROM `transaction`
             WHERE user_id = :uid AND module_source = :src
             $dateWhere
             $searchWhere
             ORDER BY $orderBy",
            $params
        );

        $generatedAt = new \DateTime();
        $pdf = $savingsPdfService->renderTransactionsPdf($rows, $range, $q, $sort, $generatedAt);

        return new Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="savings_transactions.pdf"',
        ]);
    }
}
