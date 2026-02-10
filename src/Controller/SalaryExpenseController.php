<?php

namespace App\Controller;

use App\Entity\Expense;
use App\Entity\Revenue;
use App\Entity\Transaction;
use App\Form\ExpenseType;
use App\Form\RevenueType;
use App\Repository\ExpenseRepository;
use App\Repository\RevenueRepository;
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
        EntityManagerInterface $entityManager
    ): Response {
        // -------- REVENUS : Tri (2 critères) ou Recherche --------
        $revenueSearch = trim((string) $request->query->get('revenue_search', ''));
        $revenueSort1 = $request->query->get('revenue_sort1', 'receivedAt');
        $revenueDir1 = $request->query->get('revenue_dir1', 'DESC');
        $revenueSort2 = $request->query->get('revenue_sort2', 'amount');
        $revenueDir2 = $request->query->get('revenue_dir2', 'DESC');

        if ($revenueSearch !== '') {
            $revenues = $revenueRepository->search($revenueSearch);
        } else {
            $revenues = $revenueRepository->sortByTwoCriteria($revenueSort1, $revenueDir1, $revenueSort2, $revenueDir2);
        }

        // -------- DÉPENSES : Tri (2 critères) ou Recherche --------
        $expenseSearch = trim((string) $request->query->get('expense_search', ''));
        $expenseSort1 = $request->query->get('expense_sort1', 'expenseDate');
        $expenseDir1 = $request->query->get('expense_dir1', 'DESC');
        $expenseSort2 = $request->query->get('expense_sort2', 'amount');
        $expenseDir2 = $request->query->get('expense_dir2', 'DESC');

        if ($expenseSearch !== '') {
            $expenses = $expenseRepository->search($expenseSearch);
        } else {
            $expenses = $expenseRepository->sortByTwoCriteria($expenseSort1, $expenseDir1, $expenseSort2, $expenseDir2);
        }

        $totalIncome = array_sum(array_map(fn(Revenue $r) => $r->getAmount(), $revenues));
        $totalExpenses = array_sum(array_map(fn(Expense $e) => $e->getAmount(), $expenses));
        $netBalance = $totalIncome - $totalExpenses;

        $lastTransactionDate = null;
        $lastRevenue = $revenueRepository->findOneBy([], ['receivedAt' => 'DESC']);
        $lastExpense = $expenseRepository->findOneBy([], ['expenseDate' => 'DESC']);
        if ($lastRevenue && $lastExpense) {
            $lastTransactionDate = $lastRevenue->getReceivedAt() > $lastExpense->getExpenseDate()
                ? $lastRevenue->getReceivedAt()
                : $lastExpense->getExpenseDate();
        } elseif ($lastRevenue) {
            $lastTransactionDate = $lastRevenue->getReceivedAt();
        } elseif ($lastExpense) {
            $lastTransactionDate = $lastExpense->getExpenseDate();
        }

        $revenue = new Revenue();
        $formRevenue = $this->createForm(RevenueType::class, $revenue);
        $formRevenue->handleRequest($request);
        if ($formRevenue->isSubmitted() && $formRevenue->isValid()) {
            $entityManager->persist($revenue);
            $entityManager->flush();
            $this->addFlash('success', 'Revenu ajouté.');
            return $this->redirectToRoute('app_salary_expense_index');
        }

        $expense = new Expense();
        $formExpense = $this->createForm(ExpenseType::class, $expense);
        $formExpense->handleRequest($request);
        if ($formExpense->isSubmitted() && $formExpense->isValid()) {
            // 1️⃣ Sauvegarder la dépense
            $entityManager->persist($expense);

            // 2️⃣ Créer la transaction associée
            $user = $this->getUser();
            if ($user) {
                $transaction = new Transaction();
                $transaction->setType('EXPENSE');
                $transaction->setMontant($expense->getAmount());
                $transaction->setDate($expense->getExpenseDate() ?? new \DateTime());
                $transaction->setDescription($expense->getDescription());
                $transaction->setModuleSource('SALARY_EXPENSE_MODULE');
                $transaction->setUser($user);
                $transaction->setExpense($expense);

                $entityManager->persist($transaction);
            }

            // 3️⃣ Flush global
            $entityManager->flush();

            $this->addFlash('success', 'Dépense ajoutée (transaction créée).');
            return $this->redirectToRoute('app_salary_expense_index');
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
        ]);
    }

    #[Route('/revenue/{id}/edit', name: 'revenue_edit', methods: ['GET', 'POST'])]
    public function editRevenue(Request $request, Revenue $revenue, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(RevenueType::class, $revenue);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Revenu mis à jour.');
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
        if ($this->isCsrfTokenValid('delete' . $revenue->getId(), $request->request->get('_token', ''))) {
            $entityManager->remove($revenue);
            $entityManager->flush();
            $this->addFlash('success', 'Revenu supprimé.');
        }

        return $this->redirectToRoute('app_salary_expense_index');
    }

    #[Route('/expense/{id}/edit', name: 'expense_edit', methods: ['GET', 'POST'])]
    public function editExpense(Request $request, Expense $expense, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ExpenseType::class, $expense);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Dépense mise à jour.');
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
        if ($this->isCsrfTokenValid('delete' . $expense->getId(), $request->request->get('_token', ''))) {
            $entityManager->remove($expense);
            $entityManager->flush();
            $this->addFlash('success', 'Dépense supprimée.');
        }

        return $this->redirectToRoute('app_salary_expense_index');
    }
}
