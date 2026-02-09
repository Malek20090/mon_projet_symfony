<?php

namespace App\Controller;

use App\Entity\FinancialGoal;
use App\Repository\FinancialGoalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/financial/goal')]
final class FinancialGoalController extends AbstractController
{
    #[Route('', name: 'app_financial_goal_index', methods: ['GET'])]
    public function index(Request $request, FinancialGoalRepository $repo): Response
    {
        // GET params from your toolbar
        $q      = trim((string) $request->query->get('q', ''));
        $sort   = (string) $request->query->get('sort', '');
        $filter = (string) $request->query->get('filter', 'all');

        // Build query (search/sort/filter)
        $qb = $repo->createQueryBuilder('g');

        // SEARCH (by name)
        if ($q !== '') {
            $qb->andWhere('LOWER(g.nom) LIKE :q')
               ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        // FILTER chips
        // high => priority 1 or 2
        if ($filter === 'high') {
            $qb->andWhere('g.priorite <= 2');
        }

        // near => deadline within next 14 days
        if ($filter === 'near') {
            $today = new \DateTimeImmutable('today');
            $limit = $today->modify('+14 days');

            $qb->andWhere('g.dateLimite IS NOT NULL')
               ->andWhere('g.dateLimite BETWEEN :t1 AND :t2')
               ->setParameter('t1', $today)
               ->setParameter('t2', $limit);
        }

        // SORT
        switch ($sort) {
            case 'priority_desc':
                $qb->orderBy('g.priorite', 'ASC'); // P1 highest => ASC
                break;

            case 'deadline_asc':
                // nulls last (simple approach: order by date asc, nulls handled by DB)
                $qb->orderBy('g.dateLimite', 'ASC');
                break;

            case 'name_asc':
                $qb->orderBy('g.nom', 'ASC');
                break;

            case 'progress_desc':
                // progress = montantActuel / montantCible
                // avoid division by zero using CASE
                $qb->addSelect(
                    '(CASE WHEN g.montantCible > 0 THEN (g.montantActuel / g.montantCible) ELSE 0 END) AS HIDDEN prog'
                )->orderBy('prog', 'DESC');
                break;

            default:
                // default order: newest by id desc (or deadline asc)
                $qb->orderBy('g.id', 'DESC');
        }

        $goals = $qb->getQuery()->getResult();

        return $this->render('financial_goal/index.html.twig', [
            // in your twig you used "goals"
            'goals' => $goals,

            // optional: if you want to show current filters in twig
            'q' => $q,
            'sort' => $sort,
            'filter' => $filter,
        ]);
    }

    #[Route('/new', name: 'app_financial_goal_new', methods: ['POST'])]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        // matches your left form inputs:
        // nom, montant_cible, montant_actuel, date_limite, priorite
        $nom = trim((string) $request->request->get('nom', ''));
        $montantCible = (float) $request->request->get('montant_cible', 0);
        $montantActuel = (float) $request->request->get('montant_actuel', 0);
        $priorite = (int) $request->request->get('priorite', 3);
        $dateLimiteStr = (string) $request->request->get('date_limite', '');

        if ($nom === '') {
            $this->addFlash('danger', 'Goal name is required.');
            return $this->redirectToRoute('app_financial_goal_index');
        }

        if ($montantCible <= 0) {
            $this->addFlash('danger', 'Target must be greater than 0.');
            return $this->redirectToRoute('app_financial_goal_index');
        }

        if ($priorite < 1) $priorite = 1;
        if ($priorite > 5) $priorite = 5;

        $goal = new FinancialGoal();
        $goal->setNom($nom);
        $goal->setMontantCible($montantCible);
        $goal->setMontantActuel(max(0, $montantActuel));
        $goal->setPriorite($priorite);

        if ($dateLimiteStr !== '') {
            try {
                $goal->setDateLimite(new \DateTimeImmutable($dateLimiteStr));
            } catch (\Throwable $e) {
                // ignore bad date, keep null
            }
        }

        $em->persist($goal);
        $em->flush();

        $this->addFlash('success', 'Goal created successfully ✅');
        return $this->redirectToRoute('app_financial_goal_index');
    }

    #[Route('/{id}/contribute', name: 'app_financial_goal_contribute', methods: ['POST'])]
    public function contribute(int $id, Request $request, FinancialGoalRepository $repo, EntityManagerInterface $em): Response
    {
        $goal = $repo->find($id);
        if (!$goal) {
            $this->addFlash('danger', 'Goal not found.');
            return $this->redirectToRoute('app_financial_goal_index');
        }

        $add = (float) $request->request->get('add_amount', 0);
        if ($add <= 0) {
            $this->addFlash('danger', 'Contribution must be greater than 0.');
            return $this->redirectToRoute('app_financial_goal_index');
        }

        $goal->setMontantActuel($goal->getMontantActuel() + $add);
        $em->flush();

        $this->addFlash('success', 'Contribution added ✅');
        return $this->redirectToRoute('app_financial_goal_index');
    }

    #[Route('/{id}/edit', name: 'app_financial_goal_edit', methods: ['POST'])]
    public function edit(int $id, Request $request, FinancialGoalRepository $repo, EntityManagerInterface $em): Response
    {
        $goal = $repo->find($id);
        if (!$goal) {
            $this->addFlash('danger', 'Goal not found.');
            return $this->redirectToRoute('app_financial_goal_index');
        }

        // modal fields: nom, montant_cible, priorite, date_limite
        $nom = trim((string) $request->request->get('nom', $goal->getNom()));
        $montantCible = (float) $request->request->get('montant_cible', $goal->getMontantCible());
        $priorite = (int) $request->request->get('priorite', $goal->getPriorite());
        $dateLimiteStr = (string) $request->request->get('date_limite', '');

        if ($nom === '') {
            $this->addFlash('danger', 'Goal name is required.');
            return $this->redirectToRoute('app_financial_goal_index');
        }

        if ($montantCible <= 0) {
            $this->addFlash('danger', 'Target must be greater than 0.');
            return $this->redirectToRoute('app_financial_goal_index');
        }

        if ($priorite < 1) $priorite = 1;
        if ($priorite > 5) $priorite = 5;

        $goal->setNom($nom);
        $goal->setMontantCible($montantCible);
        $goal->setPriorite($priorite);

        // empty date => null
        if (trim($dateLimiteStr) === '') {
            $goal->setDateLimite(null);
        } else {
            try {
                $goal->setDateLimite(new \DateTimeImmutable($dateLimiteStr));
            } catch (\Throwable $e) {
                // keep old date if invalid
            }
        }

        $em->flush();

        $this->addFlash('success', 'Goal updated ✅');
        return $this->redirectToRoute('app_financial_goal_index');
    }

    #[Route('/{id}/delete', name: 'app_financial_goal_delete', methods: ['POST'])]
    public function delete(int $id, FinancialGoalRepository $repo, EntityManagerInterface $em): Response
    {
        $goal = $repo->find($id);
        if (!$goal) {
            $this->addFlash('danger', 'Goal not found.');
            return $this->redirectToRoute('app_financial_goal_index');
        }

        $em->remove($goal);
        $em->flush();

        $this->addFlash('success', 'Goal deleted ✅');
        return $this->redirectToRoute('app_financial_goal_index');
    }
}
