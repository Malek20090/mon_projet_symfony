<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;

#[IsGranted('ROLE_SALARY')]
class SalaryController extends AbstractController
{
    #[Route('/salary', name: 'salary_dashboard')]
    public function index()
    {
        return $this->render('salary/index.html.twig');
    }
}
