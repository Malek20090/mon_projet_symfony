<?php

namespace App\Controller\Admin;

use App\Entity\FinancialGoal;
use App\Form\FinancialGoalType;
use App\Repository\FinancialGoalRepository;
use App\Service\GoalPdfService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/goals')]
class AdminGoalController extends AbstractController
{
    #[Route('', name: 'admin_goal_index', methods: ['GET'])]
    public function index(Request $request, FinancialGoalRepository $repo): Response
    {
        $q = $request->query->get('q');
        $sort = $request->query->get('sort', 'priority_desc');

        // admin: global list (no saving account restriction)
        $goals = $repo->createQueryBuilder('g')
            ->andWhere(':q IS NULL OR :q = \'\' OR LOWER(g.nom) LIKE :like OR g.priorite = :prio')
            ->setParameter('q', $q)
            ->setParameter('like', '%'.mb_strtolower((string)$q).'%')
            ->setParameter('prio', is_numeric($q) ? (int)$q : -999)
            ->getQuery()
            ->getResult();

        // simple PHP sort fallback for admin (keep it simple)
        usort($goals, function(FinancialGoal $a, FinancialGoal $b) use ($sort) {
            if ($sort === 'deadline_asc') {
                return ($a->getDateLimite()?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->getDateLimite()?->getTimestamp() ?? PHP_INT_MAX);
            }
            if ($sort === 'name_asc') {
                return strcmp(mb_strtolower($a->getNom()), mb_strtolower($b->getNom()));
            }
            // default priority desc
            return ($b->getPriorite() ?? 0) <=> ($a->getPriorite() ?? 0);
        });

        return $this->render('admin/goal/index.html.twig', [
            'goals' => $goals,
            'q' => $q,
            'sort' => $sort,
        ]);
    }

    #[Route('/{id}', name: 'admin_goal_show', methods: ['GET'])]
    public function show(FinancialGoal $goal): Response
    {
        return $this->render('admin/goal/show.html.twig', ['goal' => $goal]);
    }

    #[Route('/{id}/edit', name: 'admin_goal_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, FinancialGoal $goal, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(FinancialGoalType::class, $goal);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            return $this->redirectToRoute('admin_goal_index');
        }

        return $this->render('admin/goal/edit.html.twig', [
            'goal' => $goal,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_goal_delete', methods: ['POST'])]
    public function delete(Request $request, FinancialGoal $goal, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('admin_delete_goal_'.$goal->getId(), (string)$request->request->get('_token'))) {
            $em->remove($goal);
            $em->flush();
        }
        return $this->redirectToRoute('admin_goal_index');
    }

    #[Route('/export/pdf', name: 'admin_goal_export_pdf', methods: ['GET'])]
    public function exportPdf(FinancialGoalRepository $repo, GoalPdfService $pdf): Response
    {
        $goals = $repo->findAll();
        $content = $pdf->renderGoalsPdf($goals);

        return new Response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="goals_report.pdf"'
        ]);
    }
}
