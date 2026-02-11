<?php

namespace App\Controller;

use App\Entity\Expense;
use App\Form\ExpenseType;
use App\Repository\ExpenseRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\Transaction;


#[Route('/expense')]
class ExpenseController extends AbstractController
{
    #[Route('/', name: 'app_expense_index', methods: ['GET'])]
    public function index(ExpenseRepository $expenseRepository): Response
    {
        return $this->render('expense/index.html.twig', [
            'expenses' => $expenseRepository->findBy([], ['expenseDate' => 'DESC', 'id' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'app_expense_new', methods: ['GET', 'POST'])]
public function new(Request $request, EntityManagerInterface $entityManager): Response
{
    $expense = new Expense();
    $form = $this->createForm(ExpenseType::class, $expense);
    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        // 🔐 utilisateur connecté
        $user = $this->getUser();

        // 1️⃣ Sauvegarder la dépense (relation Revenue conservée)
        $entityManager->persist($expense);

        // 2️⃣ Créer la transaction associée
        $transaction = new Transaction();
        $transaction->setType('EXPENSE');
        $transaction->setMontant($expense->getAmount());
        $transaction->setDate($expense->getExpenseDate() ?? new \DateTime());
        $transaction->setDescription($expense->getDescription());
        $transaction->setModuleSource('EXPENSE_MODULE');
        $transaction->setUser($user);
        $transaction->setExpense($expense);

        $entityManager->persist($transaction);

        // 3️⃣ Flush global
        $entityManager->flush();

        $this->addFlash('success', 'Dépense enregistrée + transaction créée');
        return $this->redirectToRoute('app_expense_index');
    }

    return $this->render('expense/new.html.twig', [
        'expense' => $expense,
        'form' => $form,
    ]);
}


    #[Route('/{id}', name: 'app_expense_show', methods: ['GET'])]
    public function show(Expense $expense): Response
    {
        return $this->render('expense/show.html.twig', [
            'expense' => $expense,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_expense_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Expense $expense, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(ExpenseType::class, $expense);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Dépense mise à jour.');
            return $this->redirectToRoute('app_expense_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('expense/edit.html.twig', [
            'expense' => $expense,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_expense_delete', methods: ['POST'])]
    public function delete(Request $request, Expense $expense, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete' . $expense->getId(), $request->request->get('_token', ''))) {
            $entityManager->remove($expense);
            $entityManager->flush();
            $this->addFlash('success', 'Dépense supprimée.');
        }

        return $this->redirectToRoute('app_expense_index', [], Response::HTTP_SEE_OTHER);
    }
}
