<?php

namespace App\Controller;

use App\Entity\Expense;
use App\Entity\Revenue;
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
        $revenues = $revenueRepository->findBy([], ['receivedAt' => 'DESC', 'id' => 'DESC']);
        $expenses = $expenseRepository->findBy([], ['expenseDate' => 'DESC', 'id' => 'DESC']);

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
            $entityManager->persist($expense);
            $entityManager->flush();
            $this->addFlash('success', 'Dépense ajoutée.');
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
}
