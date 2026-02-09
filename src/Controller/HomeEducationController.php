<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeEducationController extends AbstractController
{
    #[Route('/homeeducation', name: 'app_home_education')]
    public function index(): Response
    {
        return $this->render('home/index_new.html.twig');
    }
    
    #[Route('/homeeducation-old', name: 'app_home_education_old')]
    public function indexOld(): Response
    {
        return $this->render('home/index.html.twig');
    }
}
