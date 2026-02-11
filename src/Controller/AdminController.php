<?php

namespace App\Controller;

use App\Entity\Imprevus;
use App\Entity\CasRelles;
use App\Service\SecurityFundService;
use App\Repository\ImprevusRepository;
use App\Repository\CasRellesRepository;
use App\Repository\SavingAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class AdminController extends AbstractController
{
    #[Route('/admin', name: 'admin_index')]
    public function index(ImprevusRepository $imprevusRepository): Response
    {
        return $this->render('admin/index.html.twig', [
            'imprevus' => $imprevusRepository->findAll(),
        ]);
    }

    // ==================== IMPRÉVUS CRUD ====================

    #[Route('/admin/imprevus', name: 'admin_imprevus_list')]
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

    #[Route('/admin/imprevus/add', name: 'admin_imprevus_add')]
    public function addImprevus(Request $req, EntityManagerInterface $em): Response
    {
        $imprevus = new Imprevus();
        
        if ($req->isMethod('POST')) {
            $titre = $req->request->get('titre');
            $type = $req->request->get('type');
            $budget = $req->request->get('budget');
            $message = $req->request->get('message');
            
            // Validation PHP
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

    #[Route('/admin/imprevus/edit/{id}', name: 'admin_imprevus_edit')]
    public function editImprevus(Imprevus $imprevus, Request $req, EntityManagerInterface $em): Response
    {
        if ($req->isMethod('POST')) {
            $titre = $req->request->get('titre');
            $type = $req->request->get('type');
            $budget = $req->request->get('budget');
            $message = $req->request->get('message');
            
            // Validation PHP
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

    #[Route('/admin/imprevus/delete/{id}', name: 'admin_imprevus_delete')]
    public function deleteImprevus(Imprevus $imprevus, EntityManagerInterface $em, Request $request): Response
    {
        // CSRF check
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

    // ==================== CAS RÉELS CRUD ====================

    #[Route('/admin/casrelles', name: 'admin_casrelles_list')]
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

    #[Route('/admin/casrelles/all', name: 'admin_casrelles_all')]
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

    #[Route('/admin/casrelles/add', name: 'admin_casrelles_add')]
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
            
            // Validation PHP
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
                $errors['dateEffet'] = 'La date d\'effet est obligatoire.';
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
                        
                        // Validate balance for NEGATIF type
                        if ($type === 'NEGATIF' && $epargne->getSold() < $cas->getMontant()) {
                            $errors['montant'] = 'Solde insuffisant dans le compte d\'épargne! Solde actuel: ' . $epargne->getSold() . ' DT';
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

    #[Route('/admin/casrelles/edit/{id}', name: 'admin_casrelles_edit')]
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
            
            // Validation PHP
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
                $errors['dateEffet'] = 'La date d\'effet est obligatoire.';
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

    #[Route('/admin/casrelles/delete/{id}', name: 'admin_casrelles_delete')]
    public function deleteCasrelles(CasRelles $cas, EntityManagerInterface $em, Request $request): Response
    {
        // CSRF check
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

    #[Route('/admin/casrelles/process/{id}', name: 'admin_casrelles_process')]
    public function processCas(CasRelles $cas, Request $req, EntityManagerInterface $em, SavingAccountRepository $epargneRepo, SecurityFundService $securityFundService): Response
    {
        if ($req->isMethod('POST')) {
            $action = $req->request->get('action');
            
            if ($action === 'accept') {
                $solution = $cas->getSolution();
                
                if ($solution === 'EPARGNE' || $solution === 'COMPTE') {
                    $epargne = $cas->getEpargne();
                    
                    if (!$epargne) {
                        $this->addFlash('error', 'Aucun compte d\'épargne sélectionné pour cette demande');
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

    #[Route('/admin/security-fund', name: 'admin_security_fund')]
    public function securityFund(Request $req, SecurityFundService $securityFundService): Response
    {
        if ($req->isMethod('POST')) {
            $amount = (float)$req->request->get('amount');
            $action = $req->request->get('action');
            
            // Validation PHP
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
