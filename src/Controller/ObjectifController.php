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
use App\Service\AiObjectiveAdvisorService;
use App\Entity\AiObjectiveReport;
use App\Service\MonteCarloSimulationService;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;

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


#[Route('/objectifs/{id}/ai-analysis', name: 'objectif_ai_analysis')]
public function aiAnalysis(
    Objectif $objectif,
    AiObjectiveAdvisorService $aiService,
    EntityManagerInterface $em
): Response {
    // 🔐 Sécurité : vérifier que l'objectif appartient à l'utilisateur
    if (!$this->isGranted('ROLE_ADMIN')) {
        foreach ($objectif->getInvestissements() as $investissement) {
            if ($investissement->getUserId() !== $this->getUser()) {
                throw $this->createAccessDeniedException();
            }
        }
    }

    // 🤖 Analyse IA
    $result = $aiService->analyze($objectif);

    // 💾 Création du rapport
    $report = new AiObjectiveReport();
    $report->setContent($result['content']);
    $report->setRiskScore($result['riskScore']);
    $report->setCreatedAt(new \DateTime());
    $report->setObjectif($objectif);

    $em->persist($report);
    $em->flush();

    return $this->redirectToRoute('objectif_ai_show', [
        'id' => $report->getId()
    ]);
}



#[Route('/ai-report/{id}', name: 'objectif_ai_show')]
public function showAiReport(AiObjectiveReport $report): Response
{
    return $this->render('objectif/ai_report.html.twig', [
        'report' => $report
    ]);
}
#[Route('/objectifs/{id}/simulation', name: 'objectif_simulation')]
public function simulation(
    Objectif $objectif,
    MonteCarloSimulationService $simulationService
): Response {

    // 🔐 Sécurité (même logique que pour l'IA)
    if (!$this->isGranted('ROLE_ADMIN')) {
        foreach ($objectif->getInvestissements() as $investissement) {
            if ($investissement->getUserId() !== $this->getUser()) {
                throw $this->createAccessDeniedException();
            }
        }
    }

    $result = $simulationService->simulate($objectif);

    return $this->render('objectif/simulation.html.twig', [
        'objectif' => $objectif,
        'result' => $result
    ]);
}
}
