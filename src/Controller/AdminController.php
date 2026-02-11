<?php

namespace App\Controller;

use App\Entity\Cours;
use App\Entity\Quiz;
use App\Entity\Imprevus;
use App\Entity\CasRelles;
use App\Form\CoursType;
use App\Form\QuizType;
use App\Repository\CoursRepository;
use App\Repository\QuizRepository;
use App\Repository\ImprevusRepository;
use App\Repository\CasRellesRepository;
use App\Repository\SavingAccountRepository;
use App\Service\SecurityFundService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminController extends AbstractController
{
    // =========================
    // ACCUEIL ADMIN
    // =========================

    #[Route('/', name: 'admin_index')]
    public function index(ImprevusRepository $imprevusRepository): Response
    {
        return $this->render('admin/index.html.twig', [
            'imprevus' => $imprevusRepository->findAll(),
        ]);
    }

    // =========================
    // COURS CRUD
    // =========================

    #[Route('/cours', name: 'admin_cours_index', methods: ['GET'])]
    public function coursIndex(Request $request, CoursRepository $coursRepository): Response
    {
        $search = $request->query->get('q');
        $typeMedia = $request->query->get('type_media');
        $sortBy = $request->query->get('sort', CoursRepository::SORT_TITRE);
        $order = $request->query->get('order', 'ASC');

        $cours = $coursRepository->searchAndSort($search, $typeMedia, $sortBy, $order);

        return $this->render('admin/cours/index.html.twig', [
            'cours' => $cours,
            'search' => $search,
            'typeMedia' => $typeMedia,
            'sortBy' => $sortBy,
            'order' => $order,
        ]);
    }

    
    #[Route('/cours/new', name: 'admin_cours_new', methods: ['GET', 'POST'])]
    public function coursNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $cour = new Cours();
        $form = $this->createForm(CoursType::class, $cour);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($cour);
            $entityManager->flush();

            $this->addFlash('success', 'Le cours a été créé avec succès.');
            return $this->redirectToRoute('admin_cours_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/cours/new.html.twig', [
            'cour' => $cour,
            'form' => $form,
        ]);
    }

    #[Route('/cours/{id}', name: 'admin_cours_show', methods: ['GET'])]
    public function coursShow(Cours $cour): Response
    {
        return $this->render('admin/cours/show.html.twig', [
            'cour' => $cour,
        ]);
    }

    #[Route('/cours/{id}/edit', name: 'admin_cours_edit', methods: ['GET', 'POST'])]
    public function coursEdit(Request $request, Cours $cour, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(CoursType::class, $cour);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le cours a été modifié avec succès.');
            return $this->redirectToRoute('admin_cours_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/cours/edit.html.twig', [
            'cour' => $cour,
            'form' => $form,
        ]);
    }

    #[Route('/cours/{id}', name: 'admin_cours_delete', methods: ['POST'])]
    public function coursDelete(Request $request, Cours $cour, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$cour->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($cour);
            $entityManager->flush();
            $this->addFlash('success', 'Le cours a été supprimé avec succès.');
        }

        return $this->redirectToRoute('admin_cours_index', [], Response::HTTP_SEE_OTHER);
    }

    // =========================
    // QUIZ CRUD
    // =========================

    #[Route('/quiz', name: 'admin_quiz_index', methods: ['GET'])]
    public function quizIndex(Request $request, QuizRepository $quizRepository, CoursRepository $coursRepository): Response
    {
        $search = $request->query->get('q');
        $courseId = $request->query->getInt('cours_id');
        $pointsMin = $request->query->getInt('points_min') ?: null;
        $pointsMax = $request->query->getInt('points_max') ?: null;
        $sortBy = $request->query->get('sort', QuizRepository::SORT_QUESTION);
        $order = $request->query->get('order', 'ASC');

        $quizzes = $quizRepository->searchAndSort($search, $courseId, $pointsMin, $pointsMax, $sortBy, $order);
        $courses = $coursRepository->findBy([], ['titre' => 'ASC']);

        return $this->render('admin/quiz/index.html.twig', [
            'quizzes' => $quizzes,
            'search' => $search,
            'courseId' => $courseId,
            'pointsMin' => $pointsMin,
            'pointsMax' => $pointsMax,
            'courses' => $courses,
            'sortBy' => $sortBy,
            'order' => $order,
        ]);
    }

    #[Route('/quiz/new', name: 'admin_quiz_new', methods: ['GET', 'POST'])]
    public function quizNew(Request $request, EntityManagerInterface $entityManager): Response
    {
        $quiz = new Quiz();
        $form = $this->createForm(QuizType::class, $quiz);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($quiz);
            $entityManager->flush();

            $this->addFlash('success', 'Le quiz a été créé avec succès.');
            return $this->redirectToRoute('admin_quiz_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/quiz/new.html.twig', [
            'quiz' => $quiz,
            'form' => $form,
        ]);
    }

    #[Route('/quiz/{id}', name: 'admin_quiz_show', methods: ['GET'])]
    public function quizShow(Quiz $quiz): Response
    {
        return $this->render('admin/quiz/show.html.twig', [
            'quiz' => $quiz,
        ]);
    }

    #[Route('/quiz/{id}/edit', name: 'admin_quiz_edit', methods: ['GET', 'POST'])]
    public function quizEdit(Request $request, Quiz $quiz, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(QuizType::class, $quiz);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Le quiz a été modifié avec succès.');
            return $this->redirectToRoute('admin_quiz_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin/quiz/edit.html.twig', [
            'quiz' => $quiz,
            'form' => $form,
        ]);
    }

    #[Route('/quiz/{id}', name: 'admin_quiz_delete', methods: ['POST'])]
    public function quizDelete(Request $request, Quiz $quiz, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$quiz->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($quiz);
            $entityManager->flush();
            $this->addFlash('success', 'Le quiz a été supprimé avec succès.');
        }

        return $this->redirectToRoute('admin_quiz_index', [], Response::HTTP_SEE_OTHER);
    }

    // =========================
    // DASHBOARD COURS/QUIZ
    // =========================

    #[Route('/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    public function dashboard(CoursRepository $coursRepository, QuizRepository $quizRepository): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'cours_count' => count($coursRepository->findAll()),
            'quiz_count' => count($quizRepository->findAll()),
            'recent_cours' => $coursRepository->findBy([], ['id' => 'DESC'], 5),
            'recent_quizzes' => $quizRepository->findBy([], ['id' => 'DESC'], 5),
        ]);
    }

    // =========================
    // IMPRÉVUS CRUD
    // =========================

    #[Route('/imprevus', name: 'admin_imprevus_list')]
    public function listImprevus(ImprevusRepository $repo, Request $request): Response
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

    #[Route('/imprevus/add', name: 'admin_imprevus_add')]
    public function addImprevus(Request $req, EntityManagerInterface $em): Response
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
                $errors['titre'] = 'Le titre ne peut pas dépasser 150 caractères.';
            }
            if (empty($type)) {
                $errors['type'] = 'Le type est obligatoire.';
            } elseif (!in_array($type, ['POSITIF', 'NEGATIF'])) {
                $errors['type'] = 'Le type doit être POSITIF ou NEGATIF.';
            }
            if ($budget === '' || $budget === null) {
                $errors['budget'] = 'Le budget est obligatoire.';
            } elseif (!is_numeric($budget) || (float)$budget < 0) {
                $errors['budget'] = 'Le budget doit être un nombre positif.';
            } elseif ((float)$budget > 1000000) {
                $errors['budget'] = 'Le budget ne peut pas dépasser 1 000 000 DT.';
            }

            if (empty($errors)) {
                $imprevus->setTitre($titre);
                $imprevus->setType($type);
                $imprevus->setBudget((float)$budget);
                $imprevus->setMessageEducatif($message);

                $em->persist($imprevus);
                $em->flush();

                $this->addFlash('success', 'Imprévu ajouté avec succès!');
                return $this->redirectToRoute('admin_imprevus_list');
            }
        }

        return $this->render('admin/imprevus/form.html.twig', [
            'imprevus' => $imprevus,
            'action' => 'Ajouter',
            'errors' => $errors ?? [],
        ]);
    }

    #[Route('/imprevus/edit/{id}', name: 'admin_imprevus_edit')]
    public function editImprevus(Imprevus $imprevus, Request $req, EntityManagerInterface $em): Response
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
                $errors['titre'] = 'Le titre ne peut pas dépasser 150 caractères.';
            }
            if (empty($type)) {
                $errors['type'] = 'Le type est obligatoire.';
            } elseif (!in_array($type, ['POSITIF', 'NEGATIF'])) {
                $errors['type'] = 'Le type doit être POSITIF ou NEGATIF.';
            }
            if ($budget === '' || $budget === null) {
                $errors['budget'] = 'Le budget est obligatoire.';
            } elseif (!is_numeric($budget) || (float)$budget < 0) {
                $errors['budget'] = 'Le budget doit être un nombre positif.';
            } elseif ((float)$budget > 1000000) {
                $errors['budget'] = 'Le budget ne peut pas dépasser 1 000 000 DT.';
            }

            if (empty($errors)) {
                $imprevus->setTitre($titre);
                $imprevus->setType($type);
                $imprevus->setBudget((float)$budget);
                $imprevus->setMessageEducatif($message);
                $em->flush();

                $this->addFlash('success', 'Imprévu modifié avec succès!');
                return $this->redirectToRoute('admin_imprevus_list');
            }
        }

        return $this->render('admin/imprevus/form.html.twig', [
            'imprevus' => $imprevus,
            'action' => 'Modifier',
            'errors' => $errors ?? [],
        ]);
    }

    #[Route('/imprevus/delete/{id}', name: 'admin_imprevus_delete')]
    public function deleteImprevus(Imprevus $imprevus, EntityManagerInterface $em, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('imprevus_delete_' . $imprevus->getId(), $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_imprevus_list');
        }

        $em->remove($imprevus);
        $em->flush();
        $this->addFlash('success', 'Imprévu supprimé avec succès!');

        return $this->redirectToRoute('admin_imprevus_list');
    }

    // =========================
    // CAS RÉELS CRUD
    // =========================

    #[Route('/casrelles', name: 'admin_casrelles_list')]
    public function casrellesList(CasRellesRepository $repo, SavingAccountRepository $epargneRepo, SecurityFundService $securityFundService, Request $request): Response
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

    #[Route('/casrelles/all', name: 'admin_casrelles_all')]
    public function casrellesAll(CasRellesRepository $repo, SavingAccountRepository $epargneRepo, SecurityFundService $securityFundService, Request $request): Response
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

    #[Route('/casrelles/add', name: 'admin_casrelles_add')]
    public function addCasrelles(Request $req, EntityManagerInterface $em, SavingAccountRepository $savingRepo): Response
    {
        $cas = new CasRelles();

        if ($req->isMethod('POST')) {
            $titre = $req->request->get('titre');
            $type = $req->request->get('type');
            $montant = $req->request->get('montant');
            $solution = $req->request->get('solution');
            $description = $req->request->get('description');
            $epargneId = $req->request->get('epargne_id');
            $dateEffet = $req->request->get('dateEffet');

            $errors = [];
            if (empty($titre)) {
                $errors['titre'] = 'Le titre est obligatoire.';
            } elseif (strlen($titre) > 150) {
                $errors['titre'] = 'Le titre ne peut pas dépasser 150 caractères.';
            }
            if (empty($type)) {
                $errors['type'] = 'Le type est obligatoire.';
            } elseif (!in_array($type, ['POSITIF', 'NEGATIF'])) {
                $errors['type'] = 'Le type doit être POSITIF ou NEGATIF.';
            }
            if (empty($montant)) {
                $errors['montant'] = 'Le montant est obligatoire.';
            } elseif (!is_numeric($montant) || (float)$montant <= 0) {
                $errors['montant'] = 'Le montant doit être un nombre positif.';
            } elseif ((float)$montant > 1000000) {
                $errors['montant'] = 'Le montant ne peut pas dépasser 1 000 000 DT.';
            }
            if (empty($solution)) {
                $errors['solution'] = 'La solution est obligatoire.';
            } elseif (!in_array($solution, ['FONDS_SECURITE', 'EPARGNE', 'FAMILLE', 'COMPTE'])) {
                $errors['solution'] = 'Solution invalide.';
            }
            if (empty($dateEffet)) {
                $errors['dateEffet'] = "La date d'effet est obligatoire.";
            }

            if (empty($errors)) {
                $user = $this->getUser();
                if (!$user) {
                    $this->addFlash('error', 'Vous devez être connecté.');
                    return $this->redirectToRoute('admin_casrelles_list');
                }
                $cas->setUser($user);
                $cas->setTitre($titre);
                $cas->setType($type);
                $cas->setMontant((float)$montant);
                $cas->setSolution($solution);
                $cas->setDescription($description);
                $cas->setResultat('EN_ATTENTE');
                $cas->setDateEffet(new \DateTime($dateEffet));

                if ($epargneId) {
                    $epargne = $savingRepo->find($epargneId);
                    if ($epargne) {
                        $cas->setEpargne($epargne);
                        if ($type === 'NEGATIF' && $epargne->getSold() < $cas->getMontant()) {
                            $errors['montant'] = "Solde insuffisant dans le compte d'épargne! Solde actuel: " . $epargne->getSold() . ' DT';
                        }
                    }
                }

                if (empty($errors)) {
                    $em->persist($cas);
                    $em->flush();

                    $this->addFlash('success', 'Demande créée avec succès!');
                    return $this->redirectToRoute('admin_casrelles_list');
                }
            }
        }

        return $this->render('admin/casrelles/form.html.twig', [
            'cas' => $cas,
            'action' => 'Ajouter',
            'errors' => $errors ?? [],
            'epargnes' => $savingRepo->findAll(),
            'securityFund' => 0,
        ]);
    }

    #[Route('/casrelles/edit/{id}', name: 'admin_casrelles_edit')]
    public function editCasrelles(CasRelles $cas, Request $req, EntityManagerInterface $em, SavingAccountRepository $savingRepo): Response
    {
        if ($req->isMethod('POST')) {
            $titre = $req->request->get('titre');
            $type = $req->request->get('type');
            $montant = $req->request->get('montant');
            $solution = $req->request->get('solution');
            $description = $req->request->get('description');
            $epargneId = $req->request->get('epargne_id');
            $dateEffet = $req->request->get('dateEffet');

            $errors = [];
            if (empty($titre)) {
                $errors['titre'] = 'Le titre est obligatoire.';
            } elseif (strlen($titre) > 150) {
                $errors['titre'] = 'Le titre ne peut pas dépasser 150 caractères.';
            }
            if (empty($type)) {
                $errors['type'] = 'Le type est obligatoire.';
            } elseif (!in_array($type, ['POSITIF', 'NEGATIF'])) {
                $errors['type'] = 'Le type doit être POSITIF ou NEGATIF.';
            }
            if (empty($montant)) {
                $errors['montant'] = 'Le montant est obligatoire.';
            } elseif (!is_numeric($montant) || (float)$montant <= 0) {
                $errors['montant'] = 'Le montant doit être un nombre positif.';
            } elseif ((float)$montant > 1000000) {
                $errors['montant'] = 'Le montant ne peut pas dépasser 1 000 000 DT.';
            }
            if (empty($solution)) {
                $errors['solution'] = 'La solution est obligatoire.';
            } elseif (!in_array($solution, ['FONDS_SECURITE', 'EPARGNE', 'FAMILLE', 'COMPTE'])) {
                $errors['solution'] = 'Solution invalide.';
            }
            if (empty($dateEffet)) {
                $errors['dateEffet'] = "La date d'effet est obligatoire.";
            }

            if (empty($errors)) {
                $cas->setTitre($titre);
                $cas->setType($type);
                $cas->setMontant((float)$montant);
                $cas->setSolution($solution);
                $cas->setDescription($description);
                $cas->setDateEffet(new \DateTime($dateEffet));

                if ($epargneId) {
                    $epargne = $savingRepo->find($epargneId);
                    if ($epargne) {
                        $cas->setEpargne($epargne);
                    }
                } else {
                    $cas->setEpargne(null);
                }

                $em->flush();
                $this->addFlash('success', 'Demande modifiée avec succès!');
                return $this->redirectToRoute('admin_casrelles_list');
            }
        }

        return $this->render('admin/casrelles/form.html.twig', [
            'cas' => $cas,
            'action' => 'Modifier',
            'errors' => $errors ?? [],
            'epargnes' => $savingRepo->findAll(),
            'securityFund' => 0,
        ]);
    }

    #[Route('/casrelles/delete/{id}', name: 'admin_casrelles_delete')]
    public function deleteCasrelles(CasRelles $cas, EntityManagerInterface $em, Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('casrelles_delete_' . $cas->getId(), $token)) {
            $this->addFlash('error', 'Token CSRF invalide.');
            return $this->redirectToRoute('admin_casrelles_list');
        }

        $em->remove($cas);
        $em->flush();
        $this->addFlash('success', 'Demande supprimée avec succès!');

        return $this->redirectToRoute('admin_casrelles_list');
    }

    #[Route('/casrelles/process/{id}', name: 'admin_casrelles_process')]
    public function processCas(CasRelles $cas, Request $req, EntityManagerInterface $em, SavingAccountRepository $epargneRepo, SecurityFundService $securityFundService): Response
    {
        if ($req->isMethod('POST')) {
            $action = $req->request->get('action');

            if ($action === 'accept') {
                $solution = $cas->getSolution();

                if ($solution === 'EPARGNE' || $solution === 'COMPTE') {
                    $epargne = $cas->getEpargne();

                    if (!$epargne) {
                        $this->addFlash('error', "Aucun compte d'épargne sélectionné pour cette demande");
                        return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                    }

                    $epargne = $epargneRepo->find($epargne->getId());

                    if ($cas->getType() === 'NEGATIF') {
                        if ($epargne->getSold() < $cas->getMontant()) {
                            $this->addFlash('error', 'Solde insuffisant! Le compte d\'épargne #' . $epargne->getId() . ' a un solde de ' . $epargne->getSold() . ' DT');
                            return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                        }
                        $epargne->setSold($epargne->getSold() - $cas->getMontant());
                        $em->flush();
                    } else {
                        $epargne->setSold($epargne->getSold() + $cas->getMontant());
                        $em->flush();
                    }
                } elseif ($solution === 'FONDS_SECURITE') {
                    if ($cas->getType() === 'NEGATIF') {
                        if (!$securityFundService->hasSufficientBalance($cas->getMontant())) {
                            $this->addFlash('error', 'Solde insuffisant dans le fonds de sécurité! Fonds actuel: ' . $securityFundService->getBalance() . ' DT');
                            return $this->redirectToRoute('admin_casrelles_process', ['id' => $cas->getId()]);
                        }
                        $securityFundService->subtract($cas->getMontant());
                    } else {
                        $securityFundService->add($cas->getMontant());
                    }
                }

                $cas->setResultat('VALIDE');
                $em->flush();
                $this->addFlash('success', 'Demande acceptée et traitée avec succès!');

            } elseif ($action === 'reject') {
                $raison = $req->request->get('raisonRefus');
                $cas->setResultat('REFUSE');
                $cas->setRaisonRefus($raison);
                $em->flush();
                $this->addFlash('warning', 'Demande refusée');
            }

            return $this->redirectToRoute('admin_casrelles_list');
        }

        return $this->render('admin/casrelles/process.html.twig', [
            'cas' => $cas,
            'securityFund' => $securityFundService->getBalance(),
        ]);
    }

    #[Route('/security-fund', name: 'admin_security_fund')]
    public function securityFund(Request $req, SecurityFundService $securityFundService): Response
    {
        if ($req->isMethod('POST')) {
            $amount = (float)$req->request->get('amount');
            $action = $req->request->get('action');

            $errors = [];
            if (empty($amount)) {
                $errors[] = 'Le montant est obligatoire.';
            } elseif (!is_numeric($amount) || $amount <= 0) {
                $errors[] = 'Le montant doit être un nombre positif.';
            } elseif ($amount > 1000000) {
                $errors[] = 'Le montant ne peut pas dépasser 1 000 000 DT.';
            }

            if (empty($errors)) {
                if ($action === 'add') {
                    $securityFundService->add($amount);
                    $this->addFlash('success', 'Fonds de sécurité augmenté de ' . $amount . ' DT');
                } elseif ($action === 'reset') {
                    $securityFundService->reset();
                    $this->addFlash('info', 'Fonds de sécurité réinitialisé à 0 DT');
                }
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            }

            return $this->redirectToRoute('admin_casrelles_list');
        }

        return $this->json([
            'securityFund' => $securityFundService->getBalance()
        ]);
    }
}
