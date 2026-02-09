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

        // Création transaction
        $transaction = new Transaction();
        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // Date auto
            $transaction->setDate(new \DateTime());

            $user = $transaction->getUser();

            // 🔴 IMPORTANT : lier transaction au user
            $user->addTransaction($transaction);

            $em->persist($transaction);
            $em->flush();

            // recalcul du solde
            $user->recalculateSolde();
            $em->flush();

            return $this->redirectToRoute('app_transaction_index');
        }

        // Filtres
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
    $query,                              // Query Doctrine
    $request->query->getInt('page', 1),  // page actuelle
    5                                   // éléments par page
);


       

        return $this->render('admin/transactions.html.twig', [
            'transactions' => $transactions,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transaction_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Transaction $transaction, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();

            $transaction->getUser()->recalculateSolde();
            $em->flush();

            return $this->redirectToRoute('app_transaction_index');
        }

        return $this->render('transaction/edit.html.twig', [
            'form' => $form,
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}', name: 'app_transaction_delete', methods: ['POST'])]
    public function delete(Request $request, Transaction $transaction, EntityManagerInterface $em): Response
    {
        $user = $transaction->getUser();

        if ($this->isCsrfTokenValid('delete'.$transaction->getId(), $request->request->get('_token'))) {
            $em->remove($transaction);
            $em->flush();

            $user->recalculateSolde();
            $em->flush();
        }

        return $this->redirectToRoute('app_transaction_index');
    }
}
