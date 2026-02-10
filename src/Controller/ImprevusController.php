<?php

namespace App\Controller;

use App\Entity\CasRelles;
use App\Service\SecurityFundService;
use App\Repository\ImprevusRepository;
use App\Repository\SavingAccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

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
    public function alea(ImprevusRepository $imprevusRepository, SavingAccountRepository $savingRepo, SecurityFundService $securityFundService): Response
    {
        $epargnes = $savingRepo->findAll();
        $securityFund = $securityFundService->getBalance();
        
        return $this->render('alea/index.html.twig', [
            'imprevus' => $imprevusRepository->findAll(),
            'epargnes' => $epargnes,
            'securityFund' => $securityFund,
        ]);
    }

    #[Route('/alea/submit', name: 'app_alea_submit', methods: ['POST'])]
    public function submitCas(Request $req, EntityManagerInterface $em, SavingAccountRepository $savingRepo): Response
    {
        try {
            $type = $req->request->get('type');
            $titre = $req->request->get('titre');
            $montant = (float)$req->request->get('montant');
            $solution = $req->request->get('solution');
            
            $cas = new CasRelles();
            $cas->setTitre($titre);
            $cas->setType($type);
            $cas->setMontant($montant);
            $cas->setSolution($solution);
            $cas->setDateEffet(new \DateTime());
            $cas->setResultat('EN_ATTENTE');
            
            if ($solution === 'EPARGNE' || $solution === 'COMPTE') {
                $epargneId = $req->request->get('epargne_id');
                if ($epargneId) {
                    $epargne = $savingRepo->find($epargneId);
                    if ($epargne) {
                        $cas->setEpargne($epargne);
                        if ($type === 'NEGATIF' && $epargne->getSold() < $montant) {
                            return $this->json([
                                'success' => false, 
                                'message' => 'Solde insuffisant! Solde actuel: ' . $epargne->getSold() . ' DT'
                            ]);
                        }
                    }
                }
            }
            
            $em->persist($cas);
            $em->flush();
            
            return $this->json([
                'success' => true, 
                'message' => 'Demande creee avec succes! En attente de validation.'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false, 
                'message' => 'Erreur: ' . $e->getMessage()
            ]);
        }
    }
}