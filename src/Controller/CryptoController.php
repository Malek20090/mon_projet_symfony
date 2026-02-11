<?php

namespace App\Controller;
use App\Service\CryptoApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

use App\Repository\CryptoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\InvestissementRepository;

use App\Service\InvestissementCalculatorService;
use Symfony\Component\HttpFoundation\Request;

use App\Repository\ObjectifRepository;


class CryptoController extends AbstractController
{
  #[Route('/cryptos', name: 'crypto_index')]
public function index(
    Request $request,
    CryptoRepository $cryptoRepository,
    InvestissementRepository $investissementRepository,
    InvestissementCalculatorService $calculator
): Response {
    $sort = $request->query->get('sort');
    $search = $request->query->get('search');

    $investissements = $investissementRepository->findAll();

    // 🔍 filtre par nom de crypto
    $investissements = $calculator->filterByCryptoName($investissements, $search);

    // ↕️ tri
    $investissements = $calculator->sortInvestissements($investissements, $sort);

    return $this->render('crypto/index.html.twig', [
        'cryptos' => $cryptoRepository->findAll(),
        'investissements' => $investissements,
        'calculator' => $calculator,
        'currentSort' => $sort,
        'currentSearch' => $search,
    ]);
}


    #[Route('/cryptos/update-prices', name: 'crypto_update_prices', methods: ['POST'])]
public function updatePrices(
    CryptoApiService $cryptoApiService,
    EntityManagerInterface $entityManager,
    \App\Service\InvestissementCalculatorService $calculator

): RedirectResponse {
    $cryptoRepository = $entityManager->getRepository(\App\Entity\Crypto::class);
    $cryptos = $cryptoRepository->findAll();

    $apiIds = [];
    foreach ($cryptos as $crypto) {
        $apiIds[] = $crypto->getApiid();
    }

    $prices = $cryptoApiService->getPrices($apiIds);

    foreach ($cryptos as $crypto) {
        $apiId = $crypto->getApiid();

        if (isset($prices[$apiId]['usd'])) {
            $crypto->setCurrentprice($prices[$apiId]['usd']);
        }
    }
    $objectifs = $entityManager->getRepository(\App\Entity\Objectif::class)->findAll();
    $calculator->updateObjectifStatus($objectifs);


    $entityManager->flush();

    return $this->redirectToRoute('crypto_index');
}

    
}
