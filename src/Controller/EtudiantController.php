<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

#[IsGranted('ROLE_ETUDIANT')]
class EtudiantController extends AbstractController
{
    #[Route('/etudiant', name: 'etudiant_dashboard')]
    public function index()
    {
        return $this->render('etudiant/index.html.twig');
    }
}
