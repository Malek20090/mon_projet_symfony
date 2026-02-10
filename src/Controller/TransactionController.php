<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Form\TransactionType;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/transaction')]
class TransactionController extends AbstractController
{
    /**
     * INDEX (LIST + CREATE) with filters + pagination
     * - GET  : show list + filters + form
     * - POST : create transaction from same page
     */
    #[Route('/', name: 'app_transaction_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        TransactionRepository $repo,
        EntityManagerInterface $em,
        PaginatorInterface $paginator
    ): Response {
        // ==========================
        // 1) CREATE TRANSACTION FORM
        // ==========================
        $transaction = new Transaction();
        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Date auto si nécessaire
            if (method_exists($transaction, 'setDate')) {
                $transaction->setDate(new \DateTime());
            }

            // Lier la transaction au user (si le formulaire fournit user)
            $user = $transaction->getUser();
            if ($user) {
                // Important pour la relation OneToMany
                if (method_exists($user, 'addTransaction')) {
                    $user->addTransaction($transaction);
                }
            }

            $em->persist($transaction);
            $em->flush();

            // Recalcul du solde (si tu utilises cette logique)
            if ($user && method_exists($user, 'recalculateSolde')) {
                $user->recalculateSolde();
                $em->flush();
            }

            return $this->redirectToRoute('app_transaction_index');
        }

        // ==========================
        // 2) FILTERS (GET params)
        // ==========================
        $type = $request->query->get('type');         // ex: SAVING / EXPENSE / null
        $from = $request->query->get('date_from');    // ex: 2026-02-01
        $to = $request->query->get('date_to');        // ex: 2026-02-09
        $userName = $request->query->get('user');     // ex: "rahma" or email depending on repo

        // QueryBuilder / Query filtered
        $query = $repo->queryWithFilters($type, $from, $to, $userName);

        // ==========================
        // 3) PAGINATION
        // ==========================
        $transactions = $paginator->paginate(
            $query,                              // Query / QueryBuilder
            $request->query->getInt('page', 1),   // current page
            5                                    // items per page
        );

        // ==========================
        // 4) RENDER ADMIN PAGE
        // ==========================
        return $this->render('admin/transactions.html.twig', [
            'transactions' => $transactions,
            'form' => $form->createView(),

            // optional: pass current filters back to template
            'filter_type' => $type,
            'filter_from' => $from,
            'filter_to' => $to,
            'filter_user' => $userName,
        ]);
    }

    /**
     * SHOW one transaction (optional if you have it)
     */
    #[Route('/{id}', name: 'app_transaction_show', methods: ['GET'])]
    public function show(Transaction $transaction): Response
    {
        return $this->render('transaction/show.html.twig', [
            'transaction' => $transaction,
        ]);
    }

    /**
     * EDIT transaction
     */
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

            // Recalcul solde user
            $user = $transaction->getUser();
            if ($user && method_exists($user, 'recalculateSolde')) {
                $user->recalculateSolde();
                $em->flush();
            }

            return $this->redirectToRoute('app_transaction_index');
        }

        return $this->render('transaction/edit.html.twig', [
            'transaction' => $transaction,
            'form' => $form->createView(),
        ]);
    }

    /**
     * DELETE transaction
     */
    #[Route('/{id}', name: 'app_transaction_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        Transaction $transaction,
        EntityManagerInterface $em
    ): Response {
        $user = $transaction->getUser();

        if ($this->isCsrfTokenValid('delete' . $transaction->getId(), (string) $request->request->get('_token'))) {
            $em->remove($transaction);
            $em->flush();

            // Recalcul solde user
            if ($user && method_exists($user, 'recalculateSolde')) {
                $user->recalculateSolde();
                $em->flush();
            }
        }

        return $this->redirectToRoute('app_transaction_index');
    }
}

