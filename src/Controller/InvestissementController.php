<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Entity\Investissement;
use App\Form\InvestissementType;
use Symfony\Component\HttpFoundation\Request;
use App\Repository\CryptoRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\InvestissementRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;




class InvestissementController extends AbstractController
{
  #[Route('/investissement/new', name: 'investissement_new')]
public function new(
    Request $request,
    CryptoRepository $cryptoRepository,
    EntityManagerInterface $entityManager
): Response {
    $investissement = new Investissement();
    $form = $this->createForm(InvestissementType::class, $investissement);
    $form->handleRequest($request);

    // préparer les prix pour JS
    $cryptos = $cryptoRepository->findAll();
    $cryptoPrices = [];

    foreach ($cryptos as $crypto) {
        $cryptoPrices[$crypto->getId()] = $crypto->getCurrentprice();
    }

    if ($form->isSubmitted() && $form->isValid()) {
        $crypto = $investissement->getCrypto();
        $amount = $investissement->getAmountInvested();

        // prix actuel au moment de l'achat
        $buyPrice = $crypto->getCurrentprice();

        // calcul serveur (obligatoire)
        $quantity = $amount / $buyPrice;

        $investissement->setBuyPrice($buyPrice);
        $investissement->setQuantity($quantity);
        $investissement->setCreatedAt(new \DateTime());
        //user 
        $investissement->setUserId($this->getUser());

        
        $entityManager->persist($investissement);
        $entityManager->flush();

        return $this->redirectToRoute('crypto_index');
    }

    return $this->render('investissement/new.html.twig', [
        'form' => $form->createView(),
        'cryptoPrices' => json_encode($cryptoPrices),
    ]);
}

#[Route('/investissement/{id}/delete', name: 'investissement_delete', methods: ['POST'])]
public function delete(
    int $id,
    InvestissementRepository $investissementRepository,
    EntityManagerInterface $entityManager
): RedirectResponse {
    $investissement = $investissementRepository->find($id);

    if ($investissement) {
        $entityManager->remove($investissement);
        $entityManager->flush();
    }

    return $this->redirectToRoute('crypto_index');
}
#[Route('/investissement/{id}/adjust', name: 'investissement_adjust')]
public function adjust(
    int $id,
    Request $request,
    InvestissementRepository $investissementRepository,
    EntityManagerInterface $entityManager
): Response {
    $investissement = $investissementRepository->find($id);

    if (!$investissement) {
        return $this->redirectToRoute('crypto_index');
    }

    if ($request->isMethod('POST')) {
    $delta = (float) $request->request->get('amount');

    $currentAmount = $investissement->getAmountInvested();
    $newAmount = $currentAmount + $delta;

    // règle métier : minimum 1 dollar
    if ($newAmount < 1) {
        $this->addFlash('error', 'Le montant investi ne peut pas être inférieur à 1 dollar.');
        return $this->redirectToRoute('investissement_adjust', ['id' => $id]);
    }

    $newQuantity = $newAmount / $investissement->getBuyPrice();

    $investissement->setAmountInvested($newAmount);
    $investissement->setQuantity($newQuantity);

    $entityManager->flush();

    return $this->redirectToRoute('crypto_index');
}


    return $this->render('investissement/adjust.html.twig', [
        'investissement' => $investissement,
    ]);
}





}
