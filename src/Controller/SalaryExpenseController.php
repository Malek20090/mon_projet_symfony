<?php

namespace App\Controller;

use App\Entity\Expense;
use App\Entity\Revenue;
use App\Entity\Transaction;
use App\Form\ExpenseType;
use App\Form\RevenueType;
use App\Repository\ExpenseRepository;
use App\Repository\RevenueRepository;
use App\Service\ExpenseStatisticsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/salary-expense', name: 'app_salary_expense_')]
class SalaryExpenseController extends AbstractController
{
    #[Route('/', name: 'index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        RevenueRepository $revenueRepository,
        ExpenseRepository $expenseRepository,
        EntityManagerInterface $entityManager,
        ExpenseStatisticsService $expenseStatisticsService
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $selectedMonth = (string) $request->query->get('month', (new \DateTime())->format('Y-m'));
        if (!preg_match('/^\d{4}\-(0[1-9]|1[0-2])$/', $selectedMonth)) {
            $selectedMonth = (new \DateTime())->format('Y-m');
        }

        $revenues = $revenueRepository->findBy(['user' => $user], ['receivedAt' => 'DESC']);
        $expenses = $expenseRepository->findBy(['user' => $user], ['expenseDate' => 'DESC']);
        $revenues = array_values(array_filter(
            $revenues,
            static fn (Revenue $revenue): bool => $revenue->getUser()->getId() === $user->getId()
        ));
        $expenses = array_values(array_filter(
            $expenses,
            static fn (Expense $expense): bool => $expense->getUser()?->getId() === $user->getId()
        ));
        $expenseStats = $expenseStatisticsService->build($expenses, $selectedMonth);
        $revenueSearch = trim((string) $request->query->get('revenue_search', ''));
        $revenueSort1 = (string) $request->query->get('revenue_sort1', 'receivedAt');
        $revenueDir1 = strtoupper((string) $request->query->get('revenue_dir1', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $revenueSort2 = (string) $request->query->get('revenue_sort2', 'amount');
        $revenueDir2 = strtoupper((string) $request->query->get('revenue_dir2', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $expenseSearch = trim((string) $request->query->get('expense_search', ''));
        $expenseSort1 = (string) $request->query->get('expense_sort1', 'expenseDate');
        $expenseDir1 = strtoupper((string) $request->query->get('expense_dir1', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $expenseSort2 = (string) $request->query->get('expense_sort2', 'amount');
        $expenseDir2 = strtoupper((string) $request->query->get('expense_dir2', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $totalIncome = array_sum(array_map(fn(Revenue $r) => $r->getAmount(), $revenues));
        $totalExpenses = array_sum(array_map(fn(Expense $e) => $e->getAmount(), $expenses));
        $netBalance = $totalIncome - $totalExpenses;

        $lastRevenueDate = null;
        foreach ($revenues as $revenueItem) {
            $date = $revenueItem->getReceivedAt();
            if ($date !== null && ($lastRevenueDate === null || $date > $lastRevenueDate)) {
                $lastRevenueDate = $date;
            }
        }
        $lastExpenseDate = null;
        foreach ($expenses as $expenseItem) {
            $date = $expenseItem->getExpenseDate();
            if ($date !== null && ($lastExpenseDate === null || $date > $lastExpenseDate)) {
                $lastExpenseDate = $date;
            }
        }
        $lastTransactionDate = null;
        if ($lastRevenueDate && $lastExpenseDate) {
            $lastTransactionDate = $lastRevenueDate > $lastExpenseDate ? $lastRevenueDate : $lastExpenseDate;
        } elseif ($lastRevenueDate) {
            $lastTransactionDate = $lastRevenueDate;
        } elseif ($lastExpenseDate) {
            $lastTransactionDate = $lastExpenseDate;
        }

        if ($revenueSearch !== '') {
            $revenues = array_values(array_filter(
                $revenues,
                static function (Revenue $revenue) use ($revenueSearch): bool {
                    $type = (string) $revenue->getType();
                    $description = (string) ($revenue->getDescription() ?? '');

                    return stripos($type, $revenueSearch) !== false || stripos($description, $revenueSearch) !== false;
                }
            ));
        }

        if ($expenseSearch !== '') {
            $expenses = array_values(array_filter(
                $expenses,
                static function (Expense $expense) use ($expenseSearch): bool {
                    $category = (string) $expense->getCategory();
                    $description = (string) ($expense->getDescription() ?? '');

                    return stripos($category, $expenseSearch) !== false || stripos($description, $expenseSearch) !== false;
                }
            ));
        }

        $revenueAllowedSorts = ['id', 'amount', 'type', 'receivedAt', 'createdAt'];
        if (!in_array($revenueSort1, $revenueAllowedSorts, true)) {
            $revenueSort1 = 'receivedAt';
        }
        if (!in_array($revenueSort2, $revenueAllowedSorts, true)) {
            $revenueSort2 = 'amount';
        }

        $expenseAllowedSorts = ['id', 'amount', 'category', 'expenseDate'];
        if (!in_array($expenseSort1, $expenseAllowedSorts, true)) {
            $expenseSort1 = 'expenseDate';
        }
        if (!in_array($expenseSort2, $expenseAllowedSorts, true)) {
            $expenseSort2 = 'amount';
        }

        $compareValues = static function (mixed $a, mixed $b, string $direction): int {
            if ($a instanceof \DateTimeInterface) {
                $a = $a->getTimestamp();
            }
            if ($b instanceof \DateTimeInterface) {
                $b = $b->getTimestamp();
            }
            if ($a === null && $b === null) {
                return 0;
            }
            if ($a === null) {
                return $direction === 'ASC' ? -1 : 1;
            }
            if ($b === null) {
                return $direction === 'ASC' ? 1 : -1;
            }

            $result = is_numeric($a) && is_numeric($b)
                ? ($a <=> $b)
                : strcasecmp((string) $a, (string) $b);

            return $direction === 'ASC' ? $result : -$result;
        };

        usort($revenues, static function (Revenue $a, Revenue $b) use ($revenueSort1, $revenueDir1, $revenueSort2, $revenueDir2, $compareValues): int {
            $firstA = match ($revenueSort1) {
                'id' => $a->getId(),
                'amount' => $a->getAmount(),
                'type' => $a->getType(),
                'createdAt' => $a->getCreatedAt(),
                default => $a->getReceivedAt(),
            };
            $firstB = match ($revenueSort1) {
                'id' => $b->getId(),
                'amount' => $b->getAmount(),
                'type' => $b->getType(),
                'createdAt' => $b->getCreatedAt(),
                default => $b->getReceivedAt(),
            };
            $firstResult = $compareValues($firstA, $firstB, $revenueDir1);
            if ($firstResult !== 0) {
                return $firstResult;
            }

            $secondA = match ($revenueSort2) {
                'id' => $a->getId(),
                'amount' => $a->getAmount(),
                'type' => $a->getType(),
                'createdAt' => $a->getCreatedAt(),
                default => $a->getReceivedAt(),
            };
            $secondB = match ($revenueSort2) {
                'id' => $b->getId(),
                'amount' => $b->getAmount(),
                'type' => $b->getType(),
                'createdAt' => $b->getCreatedAt(),
                default => $b->getReceivedAt(),
            };

            return $compareValues($secondA, $secondB, $revenueDir2);
        });

        usort($expenses, static function (Expense $a, Expense $b) use ($expenseSort1, $expenseDir1, $expenseSort2, $expenseDir2, $compareValues): int {
            $firstA = match ($expenseSort1) {
                'id' => $a->getId(),
                'amount' => $a->getAmount(),
                'category' => $a->getCategory(),
                default => $a->getExpenseDate(),
            };
            $firstB = match ($expenseSort1) {
                'id' => $b->getId(),
                'amount' => $b->getAmount(),
                'category' => $b->getCategory(),
                default => $b->getExpenseDate(),
            };
            $firstResult = $compareValues($firstA, $firstB, $expenseDir1);
            if ($firstResult !== 0) {
                return $firstResult;
            }

            $secondA = match ($expenseSort2) {
                'id' => $a->getId(),
                'amount' => $a->getAmount(),
                'category' => $a->getCategory(),
                default => $a->getExpenseDate(),
            };
            $secondB = match ($expenseSort2) {
                'id' => $b->getId(),
                'amount' => $b->getAmount(),
                'category' => $b->getCategory(),
                default => $b->getExpenseDate(),
            };

            return $compareValues($secondA, $secondB, $expenseDir2);
        });

        $revenue = new Revenue();
        $formRevenue = $this->createForm(RevenueType::class, $revenue);
        $formRevenue->handleRequest($request);
        if ($formRevenue->isSubmitted() && $formRevenue->isValid()) {
            $user = $this->getUser();
            if (!$user) {
                $this->addFlash('error', 'Vous devez etre connecte.');
                return $this->redirectToRoute('app_salary_expense_index');
            }

            $revenue->setUser($user);
            $entityManager->persist($revenue);

            $newSolde = $user->getSoldeTotal() + $revenue->getAmount();
            $user->setSoldeTotal($newSolde);

            $entityManager->flush();

            $this->addFlash('success', 'Revenu ajoute et solde mis a jour.');

            return $this->redirectToRoute('app_salary_expense_index', [
                'tab' => 'revenus',
                'month' => $revenue->getReceivedAt()->format('Y-m'),
            ]);
        }

        $expense = new Expense();
        $formExpense = $this->createForm(ExpenseType::class, $expense, [
            'user' => $user,
        ]);
        $formExpense->handleRequest($request);

        if ($formExpense->isSubmitted() && $formExpense->isValid()) {
            $user = $this->getUser();

            if (!$user) {
                $this->addFlash('error', 'Vous devez etre connecte.');
                return $this->redirectToRoute('app_salary_expense_index');
            }

            $expense->setUser($user);
            $entityManager->persist($expense);

            $user->setSoldeTotal(
                $user->getSoldeTotal() - $expense->getAmount()
            );

            $transaction = new Transaction();
            $transaction->setType('EXPENSE');
            $transaction->setMontant($expense->getAmount());
            $transaction->setDate($expense->getExpenseDate() ?? new \DateTime());
            $transaction->setDescription($expense->getDescription());
            $transaction->setModuleSource('SALARY_EXPENSE_MODULE');
            $transaction->setUser($user);
            $transaction->setExpense($expense);

            $entityManager->persist($transaction);

            $entityManager->flush();

            $this->addFlash('success', 'Depense ajoutee et solde mis a jour.');

            return $this->redirectToRoute('app_salary_expense_index', [
                'tab' => 'expenses',
                'month' => ($expense->getExpenseDate() ?? new \DateTimeImmutable())->format('Y-m'),
            ]);
        }

        return $this->render('salary_expense/index.html.twig', [
            'revenues' => $revenues,
            'expenses' => $expenses,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'netBalance' => $netBalance,
            'lastTransactionDate' => $lastTransactionDate,
            'formRevenue' => $formRevenue,
            'formExpense' => $formExpense,
            'selectedMonth' => $selectedMonth,
            'expenseStats' => $expenseStats,
        ]);
    }

    #[Route('/revenue/{id}/edit', name: 'revenue_edit', methods: ['GET', 'POST'])]
    public function editRevenue(Request $request, Revenue $revenue, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user || $revenue->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $form = $this->createForm(RevenueType::class, $revenue);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Revenu mis a jour.');
            return $this->redirectToRoute('app_salary_expense_index');
        }

        return $this->render('salary_expense/edit_revenue.html.twig', [
            'revenue' => $revenue,
            'form' => $form,
        ]);
    }

    #[Route('/revenue/{id}/delete', name: 'revenue_delete', methods: ['POST'])]
    public function deleteRevenue(Request $request, Revenue $revenue, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user || $revenue->getUser()->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        if ($this->isCsrfTokenValid('delete' . $revenue->getId(), $request->request->get('_token', ''))) {
            $owner = $revenue->getUser();
            $owner->setSoldeTotal(
                $owner->getSoldeTotal() - $revenue->getAmount()
            );

            $entityManager->remove($revenue);
            $entityManager->flush();
            $this->addFlash('success', 'Revenu supprime.');
        }

        return $this->redirectToRoute('app_salary_expense_index');
    }

    #[Route('/expense/{id}/edit', name: 'expense_edit', methods: ['GET', 'POST'])]
    public function editExpense(Request $request, Expense $expense, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user || $expense->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        $form = $this->createForm(ExpenseType::class, $expense, [
            'user' => $user,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Depense mise a jour.');
            return $this->redirectToRoute('app_salary_expense_index');
        }

        return $this->render('salary_expense/edit_expense.html.twig', [
            'expense' => $expense,
            'form' => $form,
        ]);
    }

    #[Route('/expense/{id}/delete', name: 'expense_delete', methods: ['POST'])]
    public function deleteExpense(Request $request, Expense $expense, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user || $expense->getUser()?->getId() !== $user->getId()) {
            throw $this->createAccessDeniedException('Access denied.');
        }

        if ($this->isCsrfTokenValid('delete' . $expense->getId(), $request->request->get('_token', ''))) {
            $entityManager->remove($expense);
            $entityManager->flush();
            $this->addFlash('success', 'Depense supprimee.');
        }

        return $this->redirectToRoute('app_salary_expense_index');
    }
}
