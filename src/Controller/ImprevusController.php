<?php

namespace App\Controller;

use App\Entity\CasRelles;
use App\Entity\Imprevus;
use App\Entity\User;
use App\Entity\UserNotification;
use App\Service\CaseTextAiClassifierService;
use App\Service\CasDecisionAiService;
use App\Service\RiskAnalyzerService;
use App\Service\SecurityFundService;
use App\Repository\FinancialGoalRepository;
use App\Repository\ImprevusRepository;
use App\Repository\SavingAccountRepository;
use App\Repository\CasRellesRepository;
use App\Repository\UserNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Workflow\Registry;

class ImprevusController extends AbstractController
{
    #[Route('/imprevus', name: 'app_imprevus')]
    public function index(ImprevusRepository $imprevusRepository): Response
    {
        return $this->render('imprevus/index.html.twig', [
            'imprevus' => $imprevusRepository->findAll(),
        ]);
    }

    #[Route('/alea', name: 'app_alea')]
    public function alea(
        ImprevusRepository $imprevusRepository,
        FinancialGoalRepository $financialGoalRepository,
        CasRellesRepository $casRellesRepository,
        RiskAnalyzerService $riskAnalyzer,
        SecurityFundService $securityFundService,
        UserNotificationRepository $notificationRepo,
        EntityManagerInterface $em
    ): Response
    {
        $user = $this->getUser();
        $goals = [];
        if ($user instanceof User) {
            $goals = $financialGoalRepository->createQueryBuilder('g')
                ->join('g.savingAccount', 's')
                ->where('s.user = :user')
                ->setParameter('user', $user)
                ->orderBy('g.priorite', 'DESC')
                ->addOrderBy('g.id', 'DESC')
                ->getQuery()
                ->getResult();
        }
        $securityFund = $securityFundService->getBalance();
        $refusalPopup = null;

        if ($user instanceof User) {
            $refusalPopup = $notificationRepo->findOneBy(
                ['user' => $user, 'status' => 'REFUSE', 'isRead' => false],
                ['createdAt' => 'DESC']
            );
        }

        $objectifChoices = [];
        foreach ($goals as $goal) {
            $current = (float) $goal->getMontantActuel();
            $target = (float) $goal->getMontantCible();
            $objectifChoices[] = [
                'id' => $goal->getId(),
                'name' => $goal->getNom(),
                'current' => $current,
                'target' => $target,
                'remaining' => max(0.0, $target - $current),
                'progress' => $target > 0 ? min(100.0, ($current / $target) * 100) : 0.0,
            ];
        }

        $riskInsights = [
            'suggestedIncidents' => [],
            'suggestedOpportunities' => [],
        ];
        if ($user instanceof User) {
            $userCases = $casRellesRepository->findBy(['user' => $user], ['dateEffet' => 'DESC']);
            $analysis = $riskAnalyzer->analyze($userCases);
            $riskInsights['suggestedIncidents'] = $analysis['suggestedIncidents'];
            $riskInsights['suggestedOpportunities'] = $analysis['suggestedOpportunities'];
        }

        return $this->render('alea/index.html.twig', [
            'imprevus' => $imprevusRepository->findAll(),
            'epargnes' => [],
            'objectifs' => $objectifChoices,
            'securityFund' => $securityFund,
            'refusalPopup' => $refusalPopup,
            'aiRiskInsights' => $riskInsights,
        ]);
    }

    #[Route('/alea/notification/{id}/read', name: 'app_alea_notification_read', methods: ['POST'])]
    public function markAleaNotificationRead(UserNotification $notification, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        if ($notification->getUser()?->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if (!$notification->isRead()) {
            $notification->setIsRead(true);
            $em->flush();
        }

        return $this->json(['success' => true]);
    }

    #[Route('/alea/submit', name: 'app_alea_submit', methods: ['POST'])]
    public function submitCas(
        Request $req,
        EntityManagerInterface $em,
        FinancialGoalRepository $financialGoalRepository,
        CaseTextAiClassifierService $caseTextAiClassifier
    ): Response
    {
        try {
            $type = strtoupper(trim((string) $req->request->get('type', '')));
            $titre = $req->request->get('titre');
            $description = trim((string) $req->request->get('description', ''));
            $montant = (float)$req->request->get('montant');
            $solution = $req->request->get('solution');

            $classification = $caseTextAiClassifier->classify((string) $titre, $description);
            if (!in_array($type, [CasRelles::TYPE_POSITIF, CasRelles::TYPE_NEGATIF], true)) {
                $type = (string) $classification['type'];
            }

            $cas = new CasRelles();
            $cas->setTitre($titre);
            $cas->setType($type);
            $cas->setCategorie((string) $classification['category']);
            $cas->setDescription($description !== '' ? $description : null);
            $cas->setMontant($montant);
            $cas->setSolution($solution);
            $cas->setDateEffet(new \DateTime());
            $cas->setResultat('EN_ATTENTE');

            $user = $this->getUser();
            if (!$user) {
                return $this->json([
                    'success' => false,
                    'message' => 'Vous devez etre connecte pour creer un cas.',
                ], 403);
            }
            $cas->setUser($user);

            if ($solution === 'EPARGNE' || $solution === 'COMPTE') {
                $solution = CasRelles::SOLUTION_OBJECTIF;
            }
            $cas->setSolution($solution);

            if ($solution === CasRelles::SOLUTION_OBJECTIF) {
                $financialGoalId = $req->request->get('financial_goal_id');
                if (!$financialGoalId) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Veuillez choisir un objectif financier.',
                    ]);
                }

                $financialGoal = $financialGoalRepository->find($financialGoalId);
                if (!$financialGoal) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Financial goal introuvable.',
                    ]);
                }

                if (
                    !$user instanceof User ||
                    !$financialGoal->getSavingAccount() ||
                    $financialGoal->getSavingAccount()->getUser()?->getId() !== $user->getId()
                ) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Ce financial goal ne vous appartient pas.',
                    ], 403);
                }

                $cas->setFinancialGoal($financialGoal);
                $goalCurrent = (float) $financialGoal->getMontantActuel();

                if ($type === CasRelles::TYPE_NEGATIF && $goalCurrent < $montant) {
                    return $this->json([
                        'success' => false,
                        'message' => 'Solde objectif insuffisant. Solde actuel: ' . number_format($goalCurrent, 2, ',', ' ') . ' DT'
                    ]);
                }
            }

            $justificatif = $req->files->get('justificatif');
            if ($justificatif instanceof UploadedFile) {
                $cas->setJustificatifFile($justificatif);
            }

            $em->persist($cas);
            $em->flush();

            return $this->json([
                'success' => true,
                'message' => 'Demande creee avec succes. En attente de validation.'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la creation de la demande.'
            ]);
        }
    }

    #[Route('/alea/history', name: 'imprevu_history')]
    public function history(
        CasRellesRepository $repo,
        UserNotificationRepository $notificationRepo,
        RiskAnalyzerService $riskAnalyzer
    ): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        $cas = $repo->findBy(['user' => $user], ['dateEffet' => 'DESC']);
        $notifications = $notificationRepo->findBy(['user' => $user], ['createdAt' => 'DESC'], 10);
        $stats = $this->buildHistoryStats($cas);
        $riskInsights = $riskAnalyzer->analyze($cas);

        return $this->render('alea/history.html.twig', [
            'cas' => $cas,
            'notifications' => $notifications,
            'totalPositif' => $stats['totalPositif'],
            'totalNegatif' => $stats['totalNegatif'],
            'totalNet' => $stats['totalNet'],
            'stats' => $stats,
            'riskTypology' => $riskInsights['typology'],
            'riskInsights' => $riskInsights,
        ]);
    }

    #[Route('/alea/history/stats', name: 'imprevu_history_stats', methods: ['GET'])]
    public function historyStats(CasRellesRepository $repo, RiskAnalyzerService $riskAnalyzer): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        $cas = $repo->findBy(['user' => $user], ['dateEffet' => 'DESC']);
        $stats = $this->buildHistoryStats($cas);
        $riskInsights = $riskAnalyzer->analyze($cas);
        $solutionLabels = array_keys($stats['solutionCounts']);
        $solutionValues = array_values($stats['solutionCounts']);

        return $this->render('alea/history_stats.html.twig', [
            'cas' => $cas,
            'stats' => $stats,
            'solutionLabels' => $solutionLabels,
            'solutionValues' => $solutionValues,
            'riskTypology' => $riskInsights['typology'],
            'riskInsights' => $riskInsights,
        ]);
    }

    #[Route('/alea/history/pdf', name: 'imprevu_history_pdf', methods: ['GET'])]
    public function historyPdf(CasRellesRepository $repo): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        $cas = $repo->findBy(['user' => $user], ['dateEffet' => 'DESC']);
        $stats = $this->buildHistoryStats($cas);

        $html = $this->renderView('alea/history_pdf.html.twig', [
            'cas' => $cas,
            'stats' => $stats,
            'generatedAt' => new \DateTimeImmutable(),
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="cas_reels_history.pdf"',
        ]);
    }

    private function buildHistoryStats(array $cas): array
    {
        $totalPositif = 0.0;
        $totalNegatif = 0.0;
        $statusCounts = ['EN_ATTENTE' => 0, 'VALIDE' => 0, 'REFUSE' => 0];
        $typeCounts = ['POSITIF' => 0, 'NEGATIF' => 0];
        $solutionCounts = [];

        foreach ($cas as $c) {
            $montant = (float) $c->getMontant();
            $type = (string) $c->getType();
            $status = (string) ($c->getResultat() ?? 'EN_ATTENTE');
            $solution = (string) ($c->getSolution() ?? 'N/A');

            if ($type === 'POSITIF') {
                $totalPositif += $montant;
                $typeCounts['POSITIF']++;
            } else {
                $totalNegatif += $montant;
                $typeCounts['NEGATIF']++;
            }

            if (!isset($statusCounts[$status])) {
                $statusCounts[$status] = 0;
            }
            $statusCounts[$status]++;

            if (!isset($solutionCounts[$solution])) {
                $solutionCounts[$solution] = 0;
            }
            $solutionCounts[$solution]++;
        }

        arsort($solutionCounts);

        $count = count($cas);
        $totalAmount = $totalPositif + $totalNegatif;

        return [
            'totalPositif' => $totalPositif,
            'totalNegatif' => $totalNegatif,
            'totalNet' => $totalPositif - $totalNegatif,
            'count' => $count,
            'averageAmount' => $count > 0 ? ($totalAmount / $count) : 0,
            'statusCounts' => $statusCounts,
            'typeCounts' => $typeCounts,
            'solutionCounts' => $solutionCounts,
        ];
    }

    #[Route('/imprevus-controller/admin/imprevus', name: 'admin_imprevus_list')]
    public function listImprevusCopy(ImprevusRepository $repo, Request $request): Response
    {
        $search = $request->query->get('search', '');
        $sort = $request->query->get('sort', 'id');
        $order = $request->query->get('order', 'desc');

        $imprevus = $repo->findBySearchAndSort($search, $sort, $order);

        return $this->render('admin/imprevus/list.html.twig', [
            'imprevus' => $imprevus,
            'search' => $search,
            'sort' => $sort,
            'order' => $order,
        ]);
    }

    #[Route('/imprevus-controller/admin/imprevus/add', name: 'admin_imprevus_add')]
    public function addImprevusCopy(Request $req, EntityManagerInterface $em): Response
    {
        $imprevus = new Imprevus();

        if ($req->isMethod('POST')) {
            $titre = $req->request->get('titre');
            $type = $req->request->get('type');
            $budget = $req->request->get('budget');
            $message = $req->request->get('message');

            $errors = [];
            if (empty($titre)) {
                $errors['titre'] = 'Le titre est obligatoire.';
            } elseif (strlen($titre) > 150) {
                $errors['titre'] = 'Le titre ne peut pas dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©passer 150 caractÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨res.';
            }
            if (empty($type)) {
                $errors['type'] = 'Le type est obligatoire.';
            } elseif (!in_array($type, ['POSITIF', 'NEGATIF'])) {
                $errors['type'] = 'Le type doit ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âªtre POSITIF ou NEGATIF.';
            }
            if ($budget === '' || $budget === null) {
                $errors['budget'] = 'Le budget est obligatoire.';
            } elseif (!is_numeric($budget) || (float)$budget < 0) {
                $errors['budget'] = 'Le budget doit ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âªtre un nombre positif.';
            } elseif ((float)$budget > 1000000) {
                $errors['budget'] = 'Le budget ne peut pas dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©passer 1 000 000 DT.';
            }

            if (empty($errors)) {
                $imprevus->setTitre($titre);
                $imprevus->setType($type);
                $imprevus->setBudget((float)$budget);
                $imprevus->setMessageEducatif($message);

                $em->persist($imprevus);
                $em->flush();
                $this->addFlash('success', 'Imprevu ajoutÃƒÆ’Ã‚Â© avec succes!');
                return $this->redirectToRoute('admin_imprevus_list');
            }
        }

        return $this->render('admin/imprevus/form.html.twig', [
            'imprevus' => $imprevus,
            'action' => 'Ajouter',
            'errors' => $errors ?? [],
        ]);
    }

    #[Route('/imprevus-controller/admin/imprevus/edit/{id}', name: 'admin_imprevus_edit')]
    public function editImprevusCopy(Imprevus $imprevus, Request $req, EntityManagerInterface $em): Response
    {
        if ($req->isMethod('POST')) {
            $titre = $req->request->get('titre');
            $type = $req->request->get('type');
            $budget = $req->request->get('budget');
            $message = $req->request->get('message');

            $errors = [];
            if (empty($titre)) {
                $errors['titre'] = 'Le titre est obligatoire.';
            } elseif (strlen($titre) > 150) {
                $errors['titre'] = 'Le titre ne peut pas dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©passer 150 caractÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨res.';
            }
            if (empty($type)) {
                $errors['type'] = 'Le type est obligatoire.';
            } elseif (!in_array($type, ['POSITIF', 'NEGATIF'])) {
                $errors['type'] = 'Le type doit ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âªtre POSITIF ou NEGATIF.';
            }
            if ($budget === '' || $budget === null) {
                $errors['budget'] = 'Le budget est obligatoire.';
            } elseif (!is_numeric($budget) || (float)$budget < 0) {
                $errors['budget'] = 'Le budget doit etre un nombre positif.';
            } elseif ((float)$budget > 1000000) {
                $errors['budget'] = 'Le budget ne peut pas dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©passer 1 000 000 DT.';
            }

            if (empty($errors)) {
                $imprevus->setTitre($titre);
                $imprevus->setType($type);
                $imprevus->setBudget((float)$budget);
                $imprevus->setMessageEducatif($message);
                $em->flush();

                $this->addFlash('success', 'Imprevu modife avec succes!');
                return $this->redirectToRoute('admin_imprevus_list');
            }
        }

        return $this->render('admin/imprevus/form.html.twig', [
            'imprevus' => $imprevus,
            'action' => 'Modifier',
            'errors' => $errors ?? [],
        ]);
    }

    #[Route('/imprevus-controller/admin/imprevus/delete/{id}', name: 'admin_imprevus_delete')]
    public function deleteImprevusCopy(Imprevus $imprevus, EntityManagerInterface $em, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('imprevus_delete_' . $imprevus->getId(), $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_imprevus_list');
        }

        $em->remove($imprevus);
        $em->flush();
        $this->addFlash('success', 'Imprevu supprimÃƒÆ’Ã‚Â© avec succes!');

        return $this->redirectToRoute('admin_imprevus_list');
    }

    #[Route('/imprevus-controller/admin/casrelles', name: 'admin_casrelles_list')]
    public function casrellesListCopy(CasRellesRepository $repo, SavingAccountRepository $epargneRepo, SecurityFundService $securityFundService, Request $request): Response
    {
        $search = $request->query->get('search', '');
        $sort = $request->query->get('sort', 'id');
        $order = $request->query->get('order', 'desc');
        $filter = $request->query->get('filter', 'EN_ATTENTE');

        $casrelles = $repo->findBySearchAndSort($search, $sort, $order, $filter);

        return $this->render('admin/casrelles/list.html.twig', [
            'casrelles' => $casrelles,
            'securityFund' => $securityFundService->getBalance(),
            'epargnes' => $epargneRepo->findAll(),
            'search' => $search,
            'sort' => $sort,
            'order' => $order,
            'filter' => $filter,
            'showAll' => false,
        ]);
    }

    #[Route('/imprevus-controller/admin/casrelles/process/{id}', name: 'admin_casrelles_process')]
    public function processCasCopy(
        CasRelles $cas,
        Request $req,
        EntityManagerInterface $em,
        SecurityFundService $securityFundService,
        Registry $registry,
        CasDecisionAiService $casDecisionAiService,
        MailerInterface $mailer
    ): Response
    {
        if ($req->isMethod('POST')) {
            $action = $req->request->get('action');
            $workflow = $registry->get($cas, 'cas_reelles');
            $adminUser = $this->getUser();
            $now = new \DateTimeImmutable();
            $objectifContext = $this->buildGoalContext($cas);

            if ($action === 'accept') {
                $solution = (string) $cas->getSolution();
                if ($solution === 'EPARGNE' || $solution === 'COMPTE') {
                    $solution = CasRelles::SOLUTION_OBJECTIF;
                    $cas->setSolution($solution);
                }

                if ($solution === CasRelles::SOLUTION_OBJECTIF) {
                    if (!$objectifContext['has_objectif']) {
                        $this->addFlash('error', 'Aucun objectif selectionne pour cette demande.');
                        return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                    }
                    if ($cas->getType() === CasRelles::TYPE_NEGATIF && $objectifContext['objectif_current'] < (float) $cas->getMontant()) {
                        $this->addFlash('error', 'Solde objectif insuffisant: ' . number_format((float) $objectifContext['objectif_current'], 2, ',', ' ') . ' DT.');
                        return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                    }

                    $goal = $cas->getFinancialGoal();
                    if (!$goal) {
                        $this->addFlash('error', 'Objectif introuvable pour cette demande.');
                        return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                    }

                    $current = (float) $goal->getMontantActuel();
                    $target = (float) $goal->getMontantCible();
                    $amount = (float) $cas->getMontant();

                    if ($cas->getType() === CasRelles::TYPE_NEGATIF) {
                        $goal->setMontantActuel(max(0.0, $current - $amount));
                    } else {
                        $goal->setMontantActuel(min($target, $current + $amount));
                    }
                }

                if ($solution === 'FONDS_SECURITE') {
                    if ($cas->getType() === 'NEGATIF') {
                        if (!$securityFundService->hasSufficientBalance($cas->getMontant())) {
                            $this->addFlash('error', 'Solde insuffisant dans le fonds de securite. Fonds actuel: ' . $securityFundService->getBalance() . ' DT');
                            return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                        }
                        $securityFundService->subtract($cas->getMontant());
                    } else {
                        $securityFundService->add($cas->getMontant());
                    }
                }

                if (!$workflow->can($cas, 'validate')) {
                    $this->addFlash('error', 'Transition invalide: ce cas ne peut pas etre valide.');
                    return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                }
                $workflow->apply($cas, 'validate');
                if ($adminUser instanceof User) {
                    $cas->setConfirmedBy($adminUser);
                }
                $cas->setConfirmedAt($now);

                $em->flush();
                $this->addFlash('success', 'Demande acceptee et traitee avec succes.');

            } elseif ($action === 'reject') {
                $raison = trim((string) $req->request->get('raisonRefus'));
                if (!$workflow->can($cas, 'reject')) {
                    $this->addFlash('error', 'Transition invalide: ce cas ne peut pas etre refuse.');
                    return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                }
                if ($raison === '') {
                    $objectifContext = $this->buildGoalContext($cas);
                    $raison = $casDecisionAiService->generateRefusalReason($cas, [
                        'has_objectif' => $objectifContext['has_objectif'],
                        'objectif_current' => $objectifContext['objectif_current'],
                        'objectif_target' => $objectifContext['objectif_target'],
                        'security_fund_balance' => $securityFundService->getBalance(),
                        'near_goal' => $objectifContext['near_goal'],
                    ]);
                }
                $workflow->apply($cas, 'reject');
                $cas->setRaisonRefus($raison);
                if ($adminUser instanceof User) {
                    $cas->setConfirmedBy($adminUser);
                }
                $cas->setConfirmedAt($now);
                $em->flush();
                $this->addFlash('warning', 'Demande refusee.');
            }

            if ($action === 'reject') {
                $this->createInternalNotification($cas, false, (string) $cas->getRaisonRefus(), $em);
            }

            if ($action === 'accept' || $action === 'reject') {
                $this->sendDecisionEmail($cas, $action === 'accept', $mailer);
            }

            return $this->redirectToRoute('admin_casrelles_list');
        }

        $casObjectifContext = $this->buildGoalContext($cas);

        return $this->render('admin/casrelles/process.html.twig', [
            'cas' => $cas,
            'securityFund' => $securityFundService->getBalance(),
            'casObjectifContext' => $casObjectifContext,
            'casObjectifCurrent' => $casObjectifContext['objectif_current'],
            'casObjectifTarget' => $casObjectifContext['objectif_target'],
        ]);
    }

    #[Route('/imprevus-controller/admin/casrelles/{id}/ai-refusal-reason', name: 'admin_casrelles_ai_refusal_reason', methods: ['GET'])]
    public function generateAiRefusalReason(
        CasRelles $cas,
        SecurityFundService $securityFundService,
        CasDecisionAiService $casDecisionAiService
    ): JsonResponse
    {
        try {
            $objectifContext = $this->buildGoalContext($cas);
            $reason = $casDecisionAiService->generateRefusalReason($cas, [
                'has_objectif' => $objectifContext['has_objectif'],
                'objectif_current' => $objectifContext['objectif_current'],
                'objectif_target' => $objectifContext['objectif_target'],
                'security_fund_balance' => $securityFundService->getBalance(),
                'near_goal' => $objectifContext['near_goal'],
            ]);

            return $this->json([
                'success' => true,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la gÃƒÆ’Ã‚Â©nÃƒÆ’Ã‚Â©ration AI: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/imprevus-controller/admin/casrelles/{id}/ai-decision-proposal', name: 'admin_casrelles_ai_decision_proposal', methods: ['GET'])]
    public function generateAiDecisionProposal(
        CasRelles $cas,
        SecurityFundService $securityFundService,
        CasDecisionAiService $casDecisionAiService
    ): JsonResponse
    {
        try {
            $objectifContext = $this->buildGoalContext($cas);
            $proposal = $casDecisionAiService->proposeDecision($cas, [
                'has_objectif' => $objectifContext['has_objectif'],
                'objectif_current' => $objectifContext['objectif_current'],
                'objectif_target' => $objectifContext['objectif_target'],
                'security_fund_balance' => $securityFundService->getBalance(),
                'near_goal' => $objectifContext['near_goal'],
            ]);

            return $this->json([
                'success' => true,
                'proposal' => $proposal,
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la proposition AI: ' . $e->getMessage(),
            ], 500);
        }
    }

    #[Route('/imprevus-controller/admin/casrelles/all', name: 'admin_casrelles_all')]
    public function casrellesAllCopy(CasRellesRepository $repo, SavingAccountRepository $epargneRepo, SecurityFundService $securityFundService, Request $request): Response
    {
        $search = $request->query->get('search', '');
        $sort = $request->query->get('sort', 'id');
        $order = $request->query->get('order', 'desc');
        $filter = 'all';

        $casrelles = $repo->findBySearchAndSort($search, $sort, $order, $filter);

        return $this->render('admin/casrelles/list.html.twig', [
            'casrelles' => $casrelles,
            'securityFund' => $securityFundService->getBalance(),
            'epargnes' => $epargneRepo->findAll(),
            'search' => $search,
            'sort' => $sort,
            'order' => $order,
            'filter' => $filter,
            'showAll' => true,
        ]);
    }

    #[Route('/imprevus-controller/admin/casrelles/add', name: 'admin_casrelles_add')]
    public function addCasrellesCopy(
        Request $req,
        EntityManagerInterface $em,
        FinancialGoalRepository $financialGoalRepository
    ): Response
    {
        $cas = new CasRelles();

        if ($req->isMethod('POST')) {
            $titre = $req->request->get('titre');
            $type = $req->request->get('type');
            $montant = $req->request->get('montant');
            $solution = $req->request->get('solution');
            $description = $req->request->get('description');
            $financialGoalId = $req->request->get('financial_goal_id');
            $dateEffet = $req->request->get('dateEffet');

            $errors = [];
            if (empty($titre)) {
                $errors['titre'] = 'Le titre est obligatoire.';
            } elseif (strlen($titre) > 150) {
                $errors['titre'] = 'Le titre ne peut pas dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©passer 150 caractÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨res.';
            }
            if (empty($type)) {
                $errors['type'] = 'Le type est obligatoire.';
            } elseif (!in_array($type, ['POSITIF', 'NEGATIF'])) {
                $errors['type'] = 'Le type doit ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âªtre POSITIF ou NEGATIF.';
            }
            if (empty($montant)) {
                $errors['montant'] = 'Le montant est obligatoire.';
            } elseif (!is_numeric($montant) || (float)$montant <= 0) {
                $errors['montant'] = 'Le montant doit ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âªtre un nombre positif.';
            } elseif ((float)$montant > 1000000) {
                $errors['montant'] = 'Le montant ne peut pas dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©passer 1 000 000 DT.';
            }
            if (empty($solution)) {
                $errors['solution'] = 'La solution est obligatoire.';
            } elseif (!in_array($solution, ['FONDS_SECURITE', 'OBJECTIF', 'FAMILLE', 'EPARGNE', 'COMPTE'])) {
                $errors['solution'] = 'Solution invalide.';
            }
            if (empty($dateEffet)) {
                $errors['dateEffet'] = "La date d'effet est obligatoire.";
            }

            if (empty($errors)) {
                $user = $this->getUser();
                if (!$user) {
                    $this->addFlash('error', 'Vous devez ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âªtre connectÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©.');
                    return $this->redirectToRoute('admin_casrelles_list');
                }
                $cas->setUser($user);
                $cas->setTitre($titre);
                $cas->setType($type);
                $cas->setMontant((float)$montant);
                if ($solution === 'EPARGNE' || $solution === 'COMPTE') {
                    $solution = CasRelles::SOLUTION_OBJECTIF;
                }
                $cas->setSolution($solution);
                $cas->setDescription($description);
                $cas->setResultat('EN_ATTENTE');
                $cas->setDateEffet(new \DateTime($dateEffet));


                if ($solution === CasRelles::SOLUTION_OBJECTIF) {
                    if (!$financialGoalId) {
                        $errors['financial_goal_id'] = 'Selectionner un financial goal.';
                    } else {
                        $financialGoal = $financialGoalRepository->find($financialGoalId);
                        if (!$financialGoal) {
                            $errors['financial_goal_id'] = 'Financial goal introuvable.';
                        } else {
                            if (
                                !$user instanceof User ||
                                !$financialGoal->getSavingAccount() ||
                                $financialGoal->getSavingAccount()->getUser()?->getId() !== $user->getId()
                            ) {
                                $errors['financial_goal_id'] = 'Financial goal non autorise.';
                            } else {
                                $cas->setFinancialGoal($financialGoal);
                            }
                            $goalCurrent = (float) $financialGoal->getMontantActuel();
                            if ($type === CasRelles::TYPE_NEGATIF && $goalCurrent < $cas->getMontant()) {
                                $errors['montant'] = 'Solde financial goal insuffisant: ' . number_format($goalCurrent, 2, ',', ' ') . ' DT';
                            }
                        }
                    }
                }

                if (empty($errors)) {
                    $em->persist($cas);
                    $em->flush();

                    $this->addFlash('success', 'Demande crÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©e avec succÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨s!');
                    return $this->redirectToRoute('admin_casrelles_list');
                }
            }
        }

        return $this->render('admin/casrelles/form.html.twig', [
            'cas' => $cas,
            'action' => 'Ajouter',
            'errors' => $errors ?? [],
            'financialGoals' => $financialGoalRepository->createQueryBuilder('g')
                ->join('g.savingAccount', 's')
                ->where('s.user = :user')
                ->setParameter('user', $this->getUser())
                ->orderBy('g.priorite', 'DESC')
                ->addOrderBy('g.id', 'DESC')
                ->getQuery()
                ->getResult(),
            'securityFund' => 0,
        ]);
    }

    #[Route('/imprevus-controller/admin/casrelles/edit/{id}', name: 'admin_casrelles_edit')]
    public function editCasrellesCopy(
        CasRelles $cas,
        Request $req,
        EntityManagerInterface $em,
        FinancialGoalRepository $financialGoalRepository
    ): Response
    {
        if ($req->isMethod('POST')) {
            $titre = $req->request->get('titre');
            $type = $req->request->get('type');
            $montant = $req->request->get('montant');
            $solution = $req->request->get('solution');
            $description = $req->request->get('description');
            $financialGoalId = $req->request->get('financial_goal_id');
            $dateEffet = $req->request->get('dateEffet');

            $errors = [];
            if (empty($titre)) {
                $errors['titre'] = 'Le titre est obligatoire.';
            } elseif (strlen($titre) > 150) {
                $errors['titre'] = 'Le titre ne peut pas dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©passer 150 caractÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨res.';
            }
            if (empty($type)) {
                $errors['type'] = 'Le type est obligatoire.';
            } elseif (!in_array($type, ['POSITIF', 'NEGATIF'])) {
                $errors['type'] = 'Le type doit ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âªtre POSITIF ou NEGATIF.';
            }
            if (empty($montant)) {
                $errors['montant'] = 'Le montant est obligatoire.';
            } elseif (!is_numeric($montant) || (float)$montant <= 0) {
                $errors['montant'] = 'Le montant doit ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Âªtre un nombre positif.';
            } elseif ((float)$montant > 1000000) {
                $errors['montant'] = 'Le montant ne peut pas dÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©passer 1 000 000 DT.';
            }
            if (empty($solution)) {
                $errors['solution'] = 'La solution est obligatoire.';
            } elseif (!in_array($solution, ['FONDS_SECURITE', 'OBJECTIF', 'FAMILLE', 'EPARGNE', 'COMPTE'])) {
                $errors['solution'] = 'Solution invalide.';
            }
            if (empty($dateEffet)) {
                $errors['dateEffet'] = "La date d'effet est obligatoire.";
            }

            if (empty($errors)) {
                $cas->setTitre($titre);
                $cas->setType($type);
                $cas->setMontant((float)$montant);
                if ($solution === 'EPARGNE' || $solution === 'COMPTE') {
                    $solution = CasRelles::SOLUTION_OBJECTIF;
                }
                $cas->setSolution($solution);
                $cas->setDescription($description);
                $cas->setDateEffet(new \DateTime($dateEffet));

                if ($solution === CasRelles::SOLUTION_OBJECTIF) {
                    if (!$financialGoalId) {
                        $errors['financial_goal_id'] = 'Selectionner un financial goal.';
                    } else {
                        $financialGoal = $financialGoalRepository->find($financialGoalId);
                        if (!$financialGoal) {
                            $errors['financial_goal_id'] = 'Financial goal introuvable.';
                        } else {
                            $currentUser = $this->getUser();
                            if (
                                !$currentUser instanceof User ||
                                !$financialGoal->getSavingAccount() ||
                                $financialGoal->getSavingAccount()->getUser()?->getId() !== $currentUser->getId()
                            ) {
                                $errors['financial_goal_id'] = 'Financial goal non autorise.';
                            } else {
                                $cas->setFinancialGoal($financialGoal);
                            }
                            $goalCurrent = (float) $financialGoal->getMontantActuel();
                            if ($type === CasRelles::TYPE_NEGATIF && $goalCurrent < $cas->getMontant()) {
                                $errors['montant'] = 'Solde financial goal insuffisant: ' . number_format($goalCurrent, 2, ',', ' ') . ' DT';
                            }
                        }
                    }
                } else {
                    $cas->setFinancialGoal(null);
                }
                if (empty($errors)) {
                    $em->flush();
                    $this->addFlash('success', 'Demande modifiÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©e avec succÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨s!');
                    return $this->redirectToRoute('admin_casrelles_list');
                }
            }
        }

        return $this->render('admin/casrelles/form.html.twig', [
            'cas' => $cas,
            'action' => 'Modifier',
            'errors' => $errors ?? [],
            'financialGoals' => $financialGoalRepository->createQueryBuilder('g')
                ->join('g.savingAccount', 's')
                ->where('s.user = :user')
                ->setParameter('user', $this->getUser())
                ->orderBy('g.priorite', 'DESC')
                ->addOrderBy('g.id', 'DESC')
                ->getQuery()
                ->getResult(),
            'securityFund' => 0,
        ]);
    }

    #[Route('/imprevus-controller/admin/casrelles/delete/{id}', name: 'admin_casrelles_delete')]
    public function deleteCasrellesCopy(CasRelles $cas, EntityManagerInterface $em, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('casrelles_delete_' . $cas->getId(), $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_casrelles_list');
        }

        $em->remove($cas);
        $em->flush();
        $this->addFlash('success', 'Demande supprimÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â©e avec succÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¨s!');

        return $this->redirectToRoute('admin_casrelles_list');
    }

    #[Route('/imprevus-controller/admin/imprevus/history', name: 'admin_imprevu_history')]
    public function adminHistoryCopy(CasRellesRepository $repo): Response
    {
        $user = $this->getUser();

        $cas = $repo->findBy(['user' => $user, 'resultat' => 'VALIDE'], ['dateEffet' => 'DESC']);

        $totalPositif = 0;
        $totalNegatif = 0;

        foreach ($cas as $c) {
            if ($c->getType() === 'POSITIF') {
                $totalPositif += $c->getMontant();
            } else {
                $totalNegatif += $c->getMontant();
            }
        }

        return $this->render('alea/history.html.twig', [
            'cas' => $cas,
            'totalPositif' => $totalPositif,
            'totalNegatif' => $totalNegatif,
        ]);
    }

    /**
     * @return array{
     *   has_objectif: bool,
     *   objectif_current: float,
     *   objectif_target: float,
     *   near_goal: bool
     * }
     */
    private function buildGoalContext(CasRelles $cas): array
    {
        $goal = $cas->getFinancialGoal();
        if (!$goal) {
            return [
                'has_objectif' => false,
                'objectif_current' => 0.0,
                'objectif_target' => 0.0,
                'near_goal' => false,
            ];
        }

        $current = (float) $goal->getMontantActuel();
        $target = (float) $goal->getMontantCible();
        $nearGoal = $target > 0 && (($current / $target) * 100.0) >= 80.0;

        return [
            'has_objectif' => true,
            'objectif_current' => $current,
            'objectif_target' => $target,
            'near_goal' => $nearGoal,
        ];
    }

    private function createInternalNotification(CasRelles $cas, bool $accepted, ?string $reason, EntityManagerInterface $em): void
    {
        $user = $cas->getUser();
        if (!$user) {
            return;
        }

        $notification = new UserNotification();
        $notification->setUser($user);
        $notification->setStatus($accepted ? 'VALIDE' : 'REFUSE');
        $notification->setTitle($accepted ? 'Cas reel valide' : 'Cas reel refuse');

        $message = sprintf(
            'Votre demande "%s" (%s DT) est %s.',
            (string) $cas->getTitre(),
            number_format((float) $cas->getMontant(), 2, ',', ' '),
            $accepted ? 'validee' : 'refusee'
        );
        if (!$accepted && $reason) {
            $message .= ' Raison: ' . $reason;
        }

        $notification->setMessage($message);
        $notification->setCreatedAt(new \DateTimeImmutable());
        $notification->setIsRead(false);

        $em->persist($notification);
        $em->flush();
    }

    private function sendDecisionEmail(CasRelles $cas, bool $accepted, MailerInterface $mailer): void
    {
        $user = $cas->getUser();
        if (!$user || trim($user->getEmail()) === '') {
            return;
        }

        $category = (string) ($cas->getCategorie() ?? 'AUTRE');
        $advice = $this->buildSmartDecisionAdvice($category, $accepted);
        $subject = $accepted
            ? 'Decision sur votre demande: ACCEPTEE'
            : 'Decision sur votre demande: REFUSEE';

        $decisionLine = $accepted
            ? 'Votre demande a ete acceptee.'
            : 'Votre demande a ete refusee.';

        $reasonLine = (!$accepted && $cas->getRaisonRefus())
            ? "\nRaison: " . $cas->getRaisonRefus()
            : '';

        $content = sprintf(
            "Bonjour %s,\n\n%s\n\nTitre: %s\nMontant: %s DT\nType: %s\nCategorie detectee: %s%s\n\nConseil intelligent: %s\n\nDecide$",
            (string) ($user->getNom() ?: $user->getEmail()),
            $decisionLine,
            (string) $cas->getTitre(),
            number_format((float) $cas->getMontant(), 2, ',', ' '),
            (string) $cas->getType(),
            $category,
            $reasonLine,
            $advice
        );

        try {
            $mailer->send(
                (new Email())
                    ->from('rimajelassi81@gmail.com')
                    ->to($user->getEmail())
                    ->subject($subject)
                    ->text($content)
            );
        } catch (\Throwable) {
            // Do not block admin process when mail transport fails.
        }
    }

    private function buildSmartDecisionAdvice(string $category, bool $accepted): string
    {
        if ($accepted) {
            return match ($category) {
                'VOITURE' => 'Planifie un entretien voiture mensuel pour limiter les pannes.',
                'PANNE_MAISON' => 'Prevoyez une verification preventive plomberie/electricite ce mois.',
                'SANTE' => 'Maintiens un budget prevention sante et un suivi regulier.',
                'EDUCATION' => 'Anticipe les frais education avec une enveloppe mensuelle dediee.',
                'FACTURES' => 'Automatise une reserve mensuelle pour les charges fixes.',
                default => 'Continue le suivi mensuel de tes cas pour renforcer ta resilience.',
            };
        }

        return match ($category) {
            'VOITURE' => 'Alternative: reduire le cout via un garage partenaire ou planifier un entretien cible.',
            'PANNE_MAISON' => 'Alternative: comparer plusieurs devis avant depense.',
            'SANTE' => 'Alternative: prioriser les soins urgents et etaler le reste.',
            'EDUCATION' => 'Alternative: chercher une aide/bonification ou echelonnement.',
            'FACTURES' => 'Alternative: renegocier ou lisser les factures a venir.',
            default => 'Alternative: revoir la solution choisie (fonds, objectif ou famille).',
        };
    }

    /**
     * @param CasRelles[] $cas
     * @return array{
     *   counts: array<string,int>,
     *   percentages: array<string,float>,
     *   total: int,
     *   topCategory: string,
     *   topPercent: float,
     *   exposureMessage: string
     * }
     */
    private function buildRiskTypology(array $cas): array
    {
        $counts = [
            'VOITURE' => 0,
            'SANTE' => 0,
            'MAISON' => 0,
            'AUTRE' => 0,
        ];

        foreach ($cas as $item) {
            if ((string) $item->getType() !== CasRelles::TYPE_NEGATIF) {
                continue;
            }

            $text = mb_strtolower(trim(((string) $item->getTitre()) . ' ' . ((string) $item->getDescription())));
            if ($text === '') {
                $counts['AUTRE']++;
                continue;
            }

            if (preg_match('/voiture|auto|pneu|garage|carburant|essence|accident|mecanique/u', $text)) {
                $counts['VOITURE']++;
            } elseif (preg_match('/sante|sant[eÃƒÆ’Ã‚Â©]|medecin|mÃƒÆ’Ã‚Â©decin|hopital|h[oÃƒÆ’Ã‚Â´]pital|pharmacie|soin/u', $text)) {
                $counts['SANTE']++;
            } elseif (preg_match('/maison|loyer|toit|plomberie|electricite|ÃƒÆ’Ã‚Â©lectricit[eÃƒÆ’Ã‚Â©]|menage|mÃƒÆ’Ã‚Â©nage/u', $text)) {
                $counts['MAISON']++;
            } else {
                $counts['AUTRE']++;
            }
        }

        $total = array_sum($counts);
        $percentages = [];
        foreach ($counts as $key => $value) {
            $percentages[$key] = $total > 0 ? ($value * 100 / $total) : 0.0;
        }

        $sorted = $counts;
        arsort($sorted);
        $topCategory = (string) array_key_first($sorted);
        $topPercent = $percentages[$topCategory] ?? 0.0;

        $labels = [
            'VOITURE' => 'automobiles',
            'SANTE' => 'de santÃƒÆ’Ã‚Â©',
            'MAISON' => 'liÃƒÆ’Ã‚Â©s ÃƒÆ’Ã‚Â  la maison',
            'AUTRE' => 'divers',
        ];

        $exposureMessage = $total > 0
            ? sprintf('Tu es fortement exposÃƒÆ’Ã‚Â© aux risques %s.', $labels[$topCategory] ?? 'divers')
            : 'Pas assez de donnÃƒÆ’Ã‚Â©es pour dÃƒÆ’Ã‚Â©finir un risque dominant.';

        return [
            'counts' => $counts,
            'percentages' => $percentages,
            'total' => $total,
            'topCategory' => $topCategory,
            'topPercent' => $topPercent,
            'exposureMessage' => $exposureMessage,
        ];
    }
}
