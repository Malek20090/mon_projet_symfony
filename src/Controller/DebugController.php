<?php

namespace App\Controller;

use App\Repository\ExpenseRepository;
use App\Repository\RevenueRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class DebugController extends AbstractController
{
    #[Route('/debug/chart-data', name: 'debug_chart_data')]
    public function chartData(
        RevenueRepository $revenueRepository,
        ExpenseRepository $expenseRepository
    ): JsonResponse {
        $user = $this->getUser();
        
        if (!$user) {
            return new JsonResponse(['error' => 'Not logged in'], 401);
        }
        
        $monthlyRevenue = $revenueRepository->getMonthlyTotalsByMonth($user, 12);
        $expenseCategories = $expenseRepository->getTotalsByCategory($user);
        
        return new JsonResponse([
            'user_id' => $user->getId(),
            'monthly_revenue' => $monthlyRevenue,
            'expense_categories' => $expenseCategories,
        ]);
    }
}

