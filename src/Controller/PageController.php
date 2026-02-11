<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;

class PageController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home()
    {
        return $this->render('pages/home.html.twig');
    }

    #[Route('/about', name: 'app_about')]
    public function about()
    {
        return $this->render('pages/about.html.twig');
    }

    #[Route('/service', name: 'app_service')]
    public function service()
    {
        return $this->render('pages/service.html.twig');
    }

    #[Route('/contact', name: 'app_contact')]
    public function contact()
    {
        return $this->render('pages/contact.html.twig');
    }
}
