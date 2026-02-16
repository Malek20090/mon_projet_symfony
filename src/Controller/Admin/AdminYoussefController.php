<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\EntityManagerInterface;

use App\Repository\InvestissementRepository;
use App\Repository\ObjectifRepository;
use App\Service\InvestissementCalculatorService;
use App\Entity\Investissement;
use App\Form\AdminInvestissementCreateType;

#[Route('/adminyoussef')]
#[IsGranted('ROLE_ADMIN')]
class AdminYoussefController extends AbstractController
{
    #[Route('/dashboard', name: 'admin_dashboard')]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    // ============================
    // LISTE INVESTISSEMENTS
    // ============================
    #[Route('/investissements', name: 'admin_investissements')]
    public function investissements(
        InvestissementRepository $investissementRepository,
        InvestissementCalculatorService $calculator
    ): Response {

        $investissements = $investissementRepository->findAll();

        return $this->render('admin/investissements.html.twig', [
            'investissements' => $investissements,
            'calculator' => $calculator,
        ]);
    }

    // ============================
    // SUPPRESSION INVESTISSEMENT
    // ============================
    #[Route('/investissements/{id}/delete', name: 'admin_investissement_delete', methods: ['POST'])]
    public function deleteInvestissement(
        int $id,
        InvestissementRepository $investissementRepository,
        EntityManagerInterface $entityManager
    ): Response {

        $investissement = $investissementRepository->find($id);

        if ($investissement) {
            $entityManager->remove($investissement);
            $entityManager->flush();
        }

        return $this->redirectToRoute('admin_investissements');
    }

    // ============================
    // AJUSTER INVESTISSEMENT
    // ============================
    #[Route('/investissements/{id}/adjust', name: 'admin_investissement_adjust')]
    public function adjustInvestissement(
        int $id,
        Request $request,
        InvestissementRepository $investissementRepository,
        EntityManagerInterface $entityManager
    ): Response {

        $investissement = $investissementRepository->find($id);

        if (!$investissement) {
            return $this->redirectToRoute('admin_investissements');
        }

        if ($request->isMethod('POST')) {

            $delta = (float) $request->request->get('amount');

            $currentAmount = $investissement->getAmountInvested();
            $newAmount = $currentAmount + $delta;

            // Règle métier : minimum 1 dollar
            if ($newAmount < 1) {
                $this->addFlash('error', 'Le montant investi ne peut pas être inférieur à 1 dollar.');
                return $this->redirectToRoute('admin_investissement_adjust', ['id' => $id]);
            }

            $newQuantity = $newAmount / $investissement->getBuyPrice();

            $investissement->setAmountInvested($newAmount);
            $investissement->setQuantity($newQuantity);

            $entityManager->flush();

            return $this->redirectToRoute('admin_investissements');
        }

        return $this->render('admin/adjust_investissement.html.twig', [
            'investissement' => $investissement,
        ]);
    }

    // ============================
    // CREER INVESTISSEMENT ADMIN
    // ============================
    #[Route('/investissements/create', name: 'admin_investissement_create')]
    public function createInvestissement(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {

        $investissement = new Investissement();

        $form = $this->createForm(AdminInvestissementCreateType::class, $investissement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $crypto = $investissement->getCrypto();
            $amount = $investissement->getAmountInvested();

            $buyPrice = $crypto->getCurrentprice();
            $quantity = $amount / $buyPrice;

            $investissement->setBuyPrice($buyPrice);
            $investissement->setQuantity($quantity);
            $investissement->setCreatedAt(new \DateTime());

            $entityManager->persist($investissement);
            $entityManager->flush();

            return $this->redirectToRoute('admin_investissements');
        }

        return $this->render('admin/create_investissement.html.twig', [
            'form' => $form->createView()
        ]);
    }

    // ============================
    // LISTE OBJECTIFS
    // ============================
    #[Route('/objectifs', name: 'admin_objectifs')]
    public function objectifs(
        ObjectifRepository $objectifRepository,
        InvestissementCalculatorService $calculator
    ): Response {

        $objectifs = $objectifRepository->findAll();

        return $this->render('admin/objectifs.html.twig', [
            'objectifs' => $objectifs,
            'calculator' => $calculator,
        ]);
    }
    #[Route('/objectifs/{id}/delete', name: 'admin_objectif_delete', methods: ['POST'])]
public function deleteObjectif(
    int $id,
    \App\Repository\ObjectifRepository $objectifRepository,
    \Doctrine\ORM\EntityManagerInterface $entityManager
): Response {

    $objectif = $objectifRepository->find($id);

    if ($objectif) {
        $entityManager->remove($objectif);
        $entityManager->flush();
    }

    return $this->redirectToRoute('admin_objectifs');
}

}
