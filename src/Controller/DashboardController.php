<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\ExpenseRepository;
use App\Repository\RevenueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\UX\Chartjs\Builder\ChartBuilder;
use Symfony\UX\Chartjs\Model\Chart;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
        RevenueRepository $revenueRepository,
        ExpenseRepository $expenseRepository,
        #[Autowire(service: 'chartjs.builder')] ChartBuilder $chartBuilder
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        // Repository layer returns pre-aggregated data (no SQL/aggregation in Twig).
        $monthlyRevenueRows = $revenueRepository->getMonthlyTotalsByMonth($user, 12);
        $expenseCategoryRows = $expenseRepository->getTotalsByCategory($user);

        $monthlyRevenueLabels = array_map(
            static fn (array $row): string => (string) $row['month'],
            $monthlyRevenueRows
        );
        $monthlyRevenueValues = array_map(
            static fn (array $row): float => (float) $row['total'],
            $monthlyRevenueRows
        );

        $expenseCategoryLabels = array_map(
            static fn (array $row): string => (string) $row['category'],
            $expenseCategoryRows
        );
        $expenseCategoryValues = array_map(
            static fn (array $row): float => (float) $row['total'],
            $expenseCategoryRows
        );

        $monthlyRevenueChart = $chartBuilder->createChart(Chart::TYPE_BAR);
        $monthlyRevenueChart->setData([
            'labels' => $monthlyRevenueLabels,
            'datasets' => [[
                'label' => 'Monthly Revenues (TND)',
                'data' => $monthlyRevenueValues,
                'backgroundColor' => 'rgba(13, 110, 253, 0.70)',
                'borderColor' => 'rgba(13, 110, 253, 1)',
                'borderWidth' => 1,
                'borderRadius' => 6,
            ]],
        ]);
        $monthlyRevenueChart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => true],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'callback' => '(value) => value + " TND"',
                    ],
                ],
            ],
        ]);

        $expenseCategoryChart = $chartBuilder->createChart(Chart::TYPE_PIE);
        $expenseCategoryChart->setData([
            'labels' => $expenseCategoryLabels,
            'datasets' => [[
                'label' => 'Expenses by Category',
                'data' => $expenseCategoryValues,
                'backgroundColor' => [
                    '#0d6efd',
                    '#20c997',
                    '#ffc107',
                    '#dc3545',
                    '#6f42c1',
                    '#fd7e14',
                    '#198754',
                    '#0dcaf0',
                ],
                'borderColor' => '#ffffff',
                'borderWidth' => 2,
            ]],
        ]);
        $expenseCategoryChart->setOptions([
            'responsive' => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
            ],
        ]);

        $focus = (string) $request->query->get('focus', 'all');
        if (!in_array($focus, ['all', 'revenues', 'expenses'], true)) {
            $focus = 'all';
        }

        return $this->render('dashboard/index.html.twig', [
            'monthlyRevenueChart' => $monthlyRevenueChart,
            'expenseCategoryChart' => $expenseCategoryChart,
            'focus' => $focus,
            // Example payload exposed for debugging/inspection in Twig if needed.
            'dashboardData' => [
                'monthly_revenues' => $monthlyRevenueRows,
                'expense_categories' => $expenseCategoryRows,
            ],
        ]);
    }
}
