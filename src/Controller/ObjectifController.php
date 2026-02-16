<?php

namespace App\Controller;

use App\Entity\Objectif;
use App\Form\ObjectifType;
use App\Repository\ObjectifRepository;
use App\Service\InvestissementCalculatorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ObjectifController extends AbstractController
{
    #[Route('/objectifs/new', name: 'objectif_new')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        InvestissementCalculatorService $calculator
    ): Response {
        $objectif = new Objectif();

        $form = $this->createForm(ObjectifType::class, $objectif, [
    'current_user' => $this->getUser(),
    'is_admin' => $this->isGranted('ROLE_ADMIN'),
]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // 1️⃣ calcul du montant initial
            $initialAmount = 0;
            foreach ($objectif->getInvestissements() as $investissement) {
                $initialAmount += $investissement->getAmountInvested();
            }

            // 2️⃣ calcul du montant cible
            $targetAmount = $initialAmount * $objectif->getTargetMultiplier();

            // 3️⃣ initialisation des champs
            $objectif->setInitialAmount($initialAmount);
            $objectif->setTargetAmount($targetAmount);
            $objectif->setCreatedAt(new \DateTime());

            // 4️⃣ lier les investissements à l’objectif
            foreach ($objectif->getInvestissements() as $investissement) {
                $investissement->setObjectif($objectif);
            }

            // 5️⃣ calcul du statut AU MOMENT DE LA CRÉATION
            $currentAmount = $calculator->calculateCurrentAmountForObjectif($objectif);
            $objectif->setIsCompleted(
                $currentAmount >= $objectif->getTargetAmount()
            );

            // 6️⃣ sauvegarde
            $entityManager->persist($objectif);
            $entityManager->flush();

            return $this->redirectToRoute('objectif_index');
        }

        return $this->render('objectif/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/objectifs', name: 'objectif_index')]
public function index(
    Request $request,
    ObjectifRepository $objectifRepository,
    InvestissementCalculatorService $calculator
): Response {
    $sort = $request->query->get('sort'); // progression | target
    $order = $request->query->get('order', 'desc'); // asc | desc

    $user = $this->getUser();

if ($this->isGranted('ROLE_ADMIN')) {
    $objectifs = $objectifRepository->findAll();
} else {
    $objectifs = $objectifRepository->createQueryBuilder('o')
        ->join('o.investissements', 'i')
        ->where('i.user_id = :user')
        ->setParameter('user', $user)
        ->groupBy('o.id')
        ->getQuery()
        ->getResult();
}


    // 🔄 tri par montant cible
    if ($sort === 'target') {
        usort($objectifs, function ($a, $b) use ($order) {
            return $order === 'asc'
                ? $a->getTargetAmount() <=> $b->getTargetAmount()
                : $b->getTargetAmount() <=> $a->getTargetAmount();
        });
    }

    // 🔄 tri par progression
    if ($sort === 'progress') {
        usort($objectifs, function ($a, $b) use ($calculator, $order) {
            $progressA = $calculator->calculateCurrentAmountForObjectif($a) / max($a->getTargetAmount(), 1);
            $progressB = $calculator->calculateCurrentAmountForObjectif($b) / max($b->getTargetAmount(), 1);

            return $order === 'asc'
                ? $progressA <=> $progressB
                : $progressB <=> $progressA;
        });
    }

    return $this->render('objectif/index.html.twig', [
        'objectifs' => $objectifs,
        'calculator' => $calculator,
        'currentSort' => $sort,
        'currentOrder' => $order,
    ]);
}

    #[Route('/objectifs/{id}/delete', name: 'objectif_delete', methods: ['POST'])]
public function delete(
    Objectif $objectif,
    EntityManagerInterface $entityManager
): Response {
    // 1️⃣ détacher les investissements
    foreach ($objectif->getInvestissements() as $investissement) {
        $investissement->setObjectif(null);
    }

    // 2️⃣ supprimer l’objectif
    $entityManager->remove($objectif);
    $entityManager->flush();

    return $this->redirectToRoute('objectif_index');
}
#[Route('/objectifs/{id}/edit', name: 'objectif_edit')]
public function edit(
    Objectif $objectif,
    Request $request,
    EntityManagerInterface $entityManager,
    InvestissementCalculatorService $calculator
): Response {
    // 🔒 sauvegarde des investissements AVANT modification
    $originalInvestissements = [];
    foreach ($objectif->getInvestissements() as $investissement) {
        $originalInvestissements[] = $investissement;
    }

    $form = $this->createForm(ObjectifType::class, $objectif, [
    'current_user' => $this->getUser(),
    'is_admin' => $this->isGranted('ROLE_ADMIN'),
]);

    $form->handleRequest($request);

    if ($form->isSubmitted() && $form->isValid()) {

        // 1️⃣ détacher les investissements retirés
        foreach ($originalInvestissements as $oldInvestissement) {
            if (!$objectif->getInvestissements()->contains($oldInvestissement)) {
                $oldInvestissement->setObjectif(null);
            }
        }

        // 2️⃣ attacher les investissements actuels
        $initialAmount = 0;
        foreach ($objectif->getInvestissements() as $investissement) {
            $investissement->setObjectif($objectif);
            $initialAmount += $investissement->getAmountInvested();
        }

        // 3️⃣ recalcul des montants
        $objectif->setInitialAmount($initialAmount);
        $objectif->setTargetAmount(
            $initialAmount * $objectif->getTargetMultiplier()
        );

        // 4️⃣ recalcul du statut
        $currentAmount = $calculator->calculateCurrentAmountForObjectif($objectif);
        $objectif->setIsCompleted(
            $currentAmount >= $objectif->getTargetAmount()
        );

        $entityManager->flush();

        return $this->redirectToRoute('objectif_index');
    }

    return $this->render('objectif/edit.html.twig', [
        'objectif' => $objectif,
        'form' => $form->createView(),
    ]);
}

}
