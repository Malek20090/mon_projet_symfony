<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Form\TransactionType;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Knp\Component\Pager\PaginatorInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/transaction')]
class TransactionController extends AbstractController
{
    #[Route('/', name: 'app_transaction_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        TransactionRepository $repo,
        EntityManagerInterface $em,
        PaginatorInterface $paginator
    ): Response {

        // ================= CREATE =================
        $transaction = new Transaction();
        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $user = $transaction->getUser();

            if (!$user) {
                $this->addFlash('error', 'Veuillez choisir un utilisateur.');
                return $this->redirectToRoute('app_transaction_index');
            }

            $em->persist($transaction);
            $em->flush();

            // recalcul solde
            $user->recalculateSolde();
            $em->flush();

            $this->addFlash('success', 'Transaction ajoutée avec succès.');

            return $this->redirectToRoute('app_transaction_index');
        }

        // ================= LIST + FILTER =================

        $type = $request->query->get('type');
        $from = $request->query->get('date_from');
        $to   = $request->query->get('date_to');
        $userName = $request->query->get('user');

        $query = $repo->queryWithFilters(
            $type,
            $from,
            $to,
            $userName
        );

        $transactions = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            5
        );

        return $this->render('admin/transactions.html.twig', [
            'transactions' => $transactions,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transaction_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        Transaction $transaction,
        EntityManagerInterface $em
    ): Response {

        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $em->flush();

            $transaction->getUser()->recalculateSolde();
            $em->flush();

            $this->addFlash('success', 'Transaction modifiée.');

            return $this->redirectToRoute('app_transaction_index');
        }

        return $this->render('admin/transaction_edit.html.twig', [
            'form' => $form->createView(),
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}', name: 'app_transaction_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Transaction $transaction,
        EntityManagerInterface $em
    ): Response {

        $user = $transaction->getUser();

        if ($this->isCsrfTokenValid('delete'.$transaction->getId(), $request->request->get('_token'))) {

            $em->remove($transaction);
            $em->flush();

            if ($user) {
                $user->recalculateSolde();
                $em->flush();
            }

            $this->addFlash('success', 'Transaction supprimée.');
        }

        return $this->redirectToRoute('app_transaction_index');
    }
}
