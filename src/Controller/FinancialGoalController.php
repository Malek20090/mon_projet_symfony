<?php

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Security;

#[Route('/goals')]
final class FinancialGoalController extends AbstractController
{
    // ----------------------------
    // Helpers (same style as your SavingsController)
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
            if (($r['Key'] ?? '') === 'PRI') return (string) $r['Field'];
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

        throw new \RuntimeException("No user FK column found in `$table`.");
    }

    private function savingBalanceColumn(Connection $conn): string
    {
        $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `saving_account`");
        $colsLower = array_map('strtolower', $cols);

        foreach (['sold', 'solde', 'balance', 'montant'] as $cand) {
            if (in_array(strtolower($cand), $colsLower, true)) {
                foreach ($cols as $real) {
                    if (strtolower($real) === strtolower($cand)) return $real;
                }
                return $cand;
            }
        }
        throw new \RuntimeException("No balance column found in `saving_account`.");
    }

    private function goalAccountFkColumn(Connection $conn): string
    {
        $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `financial_goal`");
        $colsLower = array_map('strtolower', $cols);

        foreach (['saving_account_id','savingaccount_id','account_id','saving_account'] as $cand) {
            if (in_array(strtolower($cand), $colsLower, true)) {
                foreach ($cols as $real) {
                    if (strtolower($real) === strtolower($cand)) return $real;
                }
                return $cand;
            }
        }
        throw new \RuntimeException("No saving_account FK column found in `financial_goal`.");
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
                try {
                    $id = $conn->fetchOne("SELECT id FROM `$userTable` WHERE email = :email LIMIT 1", ['email' => $email]);
                    if ($id) return (int) $id;
                } catch (\Throwable $e) {}
                try {
                    $pk = $this->pkColumn($conn, $userTable);
                    $id = $conn->fetchOne("SELECT `$pk` FROM `$userTable` WHERE email = :email LIMIT 1", ['email' => $email]);
                    if ($id) return (int) $id;
                } catch (\Throwable $e) {}
            }
        }

        $manual = (int) $request->query->get('user_id', 0);
        if ($manual > 0) return $manual;

        // fallback first user
        try {
            $pk = $this->pkColumn($conn, $userTable);
            $fallback = $conn->fetchOne("SELECT `$pk` FROM `$userTable` ORDER BY `$pk` ASC LIMIT 1");
            return $fallback ? (int) $fallback : 1;
        } catch (\Throwable $e) {
            return 1;
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

    private function validateGoal(string $nom, float $target, float $current, ?string $dateLimite, int $priorite): array
    {
        $errors = [];

        $nom = trim($nom);
        if ($nom === '') $errors[] = "Le nom du goal est obligatoire.";
        if ($nom !== '' && mb_strlen($nom) < 3) $errors[] = "Le nom doit contenir au moins 3 caractères.";
        if ($nom !== '' && mb_strlen($nom) > 60) $errors[] = "Le nom ne doit pas dépasser 60 caractères.";

        if ($target <= 0) $errors[] = "Le montant cible doit être > 0.";
        if ($target > 1000000) $errors[] = "Le montant cible est trop grand (max 1,000,000).";

        if ($current < 0) $errors[] = "Le montant actuel ne peut pas être négatif.";
        if ($target > 0 && $current > $target) $errors[] = "Le montant actuel ne peut pas dépasser le montant cible.";

        if ($priorite < 1 || $priorite > 5) $errors[] = "La priorité doit être entre 1 et 5.";

        if ($dateLimite !== null) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $dateLimite);
            if (!$dt) {
                $errors[] = "Date limite invalide.";
            } else {
                $today = new \DateTimeImmutable('today');
                if ($dt < $today) $errors[] = "La date limite doit être aujourd’hui ou dans le futur.";
            }
        }

        return $errors;
    }

    private function jsonOrRedirectErrors(Request $request, array $errors, string $redirectRoute): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse([
                'ok' => false,
                'type' => 'danger',
                'errors' => $errors,
                'message' => implode(' ', $errors),
            ], 422);
        }

        foreach ($errors as $e) $this->addFlash('danger', '❌ ' . $e);
        return $this->redirectToRoute($redirectRoute);
    }

    private function jsonOrRedirectOk(Request $request, string $msg, string $redirectRoute, array $extra = []): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(array_merge([
                'ok' => true,
                'type' => 'success',
                'message' => $msg,
            ], $extra));
        }

        $this->addFlash('success', $msg);
        return $this->redirectToRoute($redirectRoute);
    }

    private function getOrCreateAccountId(Connection $conn, int $userId): int
    {
        $accUserCol = $this->userFkColumn($conn, 'saving_account');
        $accPkCol   = $this->pkColumn($conn, 'saving_account');

        $id = $conn->fetchOne(
            "SELECT `$accPkCol` FROM saving_account WHERE `$accUserCol` = :uid ORDER BY `$accPkCol` DESC LIMIT 1",
            ['uid' => $userId]
        );

        if ($id) return (int) $id;

        // create minimal account
        $balanceCol = $this->savingBalanceColumn($conn);

        $cols = $conn->fetchFirstColumn("SHOW COLUMNS FROM `saving_account`");
        $colsLower = array_map('strtolower', $cols);

        $data = [
            $accUserCol => $userId,
            $balanceCol => 0,
        ];
        if (in_array('taux_interet', $colsLower, true)) $data['taux_interet'] = 0;
        if (in_array('date_creation', $colsLower, true)) $data['date_creation'] = date('Y-m-d');

        $conn->insert('saving_account', $data);

        $id2 = $conn->fetchOne(
            "SELECT `$accPkCol` FROM saving_account WHERE `$accUserCol` = :uid ORDER BY `$accPkCol` DESC LIMIT 1",
            ['uid' => $userId]
        );
        return $id2 ? (int) $id2 : 0;
    }

    private function assertGoalBelongsToAccount(Connection $conn, int $goalId, int $accId, string $goalAccCol): bool
    {
        $ok = $conn->fetchOne(
            "SELECT 1 FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc LIMIT 1",
            ['gid' => $goalId, 'acc' => $accId]
        );
        return (bool) $ok;
    }

    // ----------------------------
    // LIST (Optional) - useful for debugging
    // ----------------------------
    #[Route('', name: 'app_goals_index', methods: ['GET'])]
    public function list(Connection $conn, Security $security, Request $request): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accId = $this->getOrCreateAccountId($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $q = trim((string) $request->query->get('q', ''));
        $sort = (string) $request->query->get('sort', 'deadline_asc');

        $allowed = [
            'deadline_asc','deadline_desc',
            'priority_desc','priority_asc',
            'progress_desc','progress_asc',
            'name_asc','name_desc',
            'id_desc','id_asc',
        ];
        if (!in_array($sort, $allowed, true)) $sort = 'deadline_asc';

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
            $where .= " AND (nom LIKE :q OR CAST(priorite AS CHAR) LIKE :q OR CAST(date_limite AS CHAR) LIKE :q)";
            $params['q'] = '%' . $q . '%';
        }

        $goals = $conn->fetchAllAssociative(
            "SELECT id, nom, montant_cible, montant_actuel, date_limite, priorite
             FROM financial_goal
             $where
             ORDER BY $orderBy",
            $params
        );

        // return JSON if asked
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['ok' => true, 'goals' => $goals]);
        }

        return $this->render('goals/index.html.twig', ['goals' => $goals, 'q' => $q, 'sort' => $sort]);
    }

    // ----------------------------
    // CREATE
    // ----------------------------
    #[Route('/new', name: 'app_goals_new', methods: ['POST'])]
    public function create(Connection $conn, Security $security, Request $request): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accId = $this->getOrCreateAccountId($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        $nom      = trim((string) $request->request->get('nom', ''));
        $target   = $this->safeFloat($request->request->get('montant_cible'));
        $current  = $this->safeFloat($request->request->get('montant_actuel'));
        $dateRaw  = (string) $request->request->get('date_limite', '');
        $priorite = $this->safeInt($request->request->get('priorite'), 3);

        $dateLimite = $this->parseYmd($dateRaw);

        $errs = $this->validateGoal($nom, $target, $current, $dateLimite, $priorite);
        if ($errs) return $this->jsonOrRedirectErrors($request, $errs, 'app_savings_index');

        $conn->insert('financial_goal', [
            'nom' => $nom,
            'montant_cible' => $target,
            'montant_actuel' => max(0, $current),
            'date_limite' => $dateLimite !== '' ? $dateLimite : null,
            'priorite' => min(5, max(1, $priorite)),
            $goalAccCol => $accId,
        ]);

        return $this->jsonOrRedirectOk($request, '✅ Goal ajouté avec succès.', 'app_savings_index');
    }

    // ----------------------------
    // EDIT
    // ----------------------------
    #[Route('/{id}/edit', name: 'app_goals_edit', methods: ['POST'])]
    public function edit(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accId = $this->getOrCreateAccountId($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        if ($id <= 0 || !$this->assertGoalBelongsToAccount($conn, $id, $accId, $goalAccCol)) {
            return $this->jsonOrRedirectErrors($request, ['Goal invalide.'], 'app_savings_index');
        }

        $nom      = trim((string) $request->request->get('nom', ''));
        $target   = $this->safeFloat($request->request->get('montant_cible'));
        $dateRaw  = (string) $request->request->get('date_limite', '');
        $priorite = $this->safeInt($request->request->get('priorite'), 3);

        $dateLimite = $this->parseYmd($dateRaw);

        // for edit: current is not edited here, keep 0 in validation for rule "current <= target"
        $errs = $this->validateGoal($nom, $target, 0, $dateLimite, $priorite);
        if ($errs) return $this->jsonOrRedirectErrors($request, $errs, 'app_savings_index');

        $data = [
            'nom' => $nom,
            'montant_cible' => $target,
            'date_limite' => $dateLimite !== '' ? $dateLimite : null,
            'priorite' => min(5, max(1, $priorite)),
        ];

        $conn->update('financial_goal', $data, ['id' => $id]);

        return $this->jsonOrRedirectOk($request, '✅ Goal modifié avec succès.', 'app_savings_index');
    }

    // ----------------------------
    // DELETE
    // ----------------------------
    #[Route('/{id}/delete', name: 'app_goals_delete', methods: ['POST'])]
    public function delete(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accId = $this->getOrCreateAccountId($conn, $userId);
        $goalAccCol = $this->goalAccountFkColumn($conn);

        if ($id <= 0 || !$this->assertGoalBelongsToAccount($conn, $id, $accId, $goalAccCol)) {
            return $this->jsonOrRedirectErrors($request, ['Goal invalide.'], 'app_savings_index');
        }

        $conn->executeStatement(
            "DELETE FROM financial_goal WHERE id = :gid AND `$goalAccCol` = :acc",
            ['gid' => $id, 'acc' => $accId]
        );

        return $this->jsonOrRedirectOk($request, '✅ Goal supprimé.', 'app_savings_index');
    }

    // ----------------------------
    // CONTRIBUTE
    // ----------------------------
    #[Route('/{id}/contribute', name: 'app_goals_contribute', methods: ['POST'])]
    public function contribute(Connection $conn, Security $security, Request $request, int $id): Response
    {
        $userId = $this->resolveUserId($conn, $security, $request);
        $accId = $this->getOrCreateAccountId($conn, $userId);

        $goalAccCol = $this->goalAccountFkColumn($conn);

        if ($id <= 0 || !$this->assertGoalBelongsToAccount($conn, $id, $accId, $goalAccCol)) {
            return $this->jsonOrRedirectErrors($request, ['Goal invalide.'], 'app_savings_index');
        }

        $add = $this->safeFloat($request->request->get('add_amount'));
        if ($add <= 0) {
            return $this->jsonOrRedirectErrors($request, ['La contribution doit être > 0.'], 'app_savings_index');
        }

        // Need saving account columns
        $accUserCol = $this->userFkColumn($conn, 'saving_account');
        $accPkCol   = $this->pkColumn($conn, 'saving_account');
        $balCol     = $this->savingBalanceColumn($conn);

        // check balance (optional, like SavingsController)
        $balance = (float) $conn->fetchOne(
            "SELECT `$balCol` FROM saving_account WHERE `$accPkCol` = :id AND `$accUserCol` = :uid LIMIT 1",
            ['id' => $accId, 'uid' => $userId]
        );

        if ($balance < $add) {
            return $this->jsonOrRedirectErrors($request, ['Solde insuffisant pour contribuer.'], 'app_savings_index');
        }

        $conn->beginTransaction();
        try {
            // - balance
            $conn->executeStatement(
                "UPDATE saving_account
                 SET `$balCol` = `$balCol` - :a
                 WHERE `$accPkCol` = :id AND `$accUserCol` = :uid",
                ['a' => $add, 'id' => $accId, 'uid' => $userId]
            );

            // + goal
            $conn->executeStatement(
                "UPDATE financial_goal
                 SET montant_actuel = montant_actuel + :a
                 WHERE id = :gid AND `$goalAccCol` = :acc",
                ['a' => $add, 'gid' => $id, 'acc' => $accId]
            );

            // history (optional)
            try {
                $conn->insert('transaction', [
                    'type' => 'GOAL_CONTRIB',
                    'montant' => $add,
                    'date' => (new \DateTime())->format('Y-m-d H:i:s'),
                    'description' => 'Contribution to goal #' . $id,
                    'module_source' => 'SAVINGS',
                    'user_id' => $userId,
                ]);
            } catch (\Throwable $e) {}

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();
            return $this->jsonOrRedirectErrors($request, ['Erreur serveur pendant la contribution.'], 'app_savings_index');
        }

        return $this->jsonOrRedirectOk($request, '✅ Contribution ajoutée.', 'app_savings_index');
    }
}
