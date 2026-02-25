<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\CoursRepository;
use App\Repository\QuizRepository;
use App\Repository\QuizResultRepository;
use App\Service\StatsStorageService;
use Symfony\UX\Chartjs\Builder\ChartBuilderInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/stats', name: 'admin_stats_')]
class AdminStatsController extends AbstractController
{
    #[Route('', name: 'index')]
    public function index(
        Request $request,
        UserRepository $userRepository,
        CoursRepository $coursRepository,
        QuizRepository $quizRepository,
        QuizResultRepository $quizResultRepository,
        StatsStorageService $statsStorage,
        ChartBuilderInterface $chartBuilder
    ): Response {
        // Get users count (students only)
        $students = $userRepository->findByRole('ROLE_ETUDIANT');
        $studentsCount = count($students);

        // Get courses count
        $coursesCount = $coursRepository->count([]);

        // Get quizzes count
        $quizzesCount = $quizRepository->count([]);

        // Get certification results from JSON storage
        $certificationResults = $statsStorage->getCertificationResults();
        $certStats = $statsStorage->getCertificationStats();
        
        $totalCertifications = $certStats['total'];
        $passedCertifications = $certStats['passed'];
        $averageScore = $certStats['averageScore'];

        // Get quiz results from database
        $quizResults = $quizResultRepository->findAllResults();
        $quizStats = $quizResultRepository->getStats();

        // Create pie chart for stats
        $statsChart = $chartBuilder->createChart(Chart::TYPE_PIE);
        $statsChart->setData([
            'labels' => ['Étudiants', 'Cours', 'Quiz'],
            'datasets' => [
                [
                    'data' => [$studentsCount, $coursesCount, $quizzesCount],
                    'backgroundColor' => [
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(75, 192, 192, 0.8)',
                        'rgba(255, 206, 86, 0.8)',
                    ],
                    'borderColor' => [
                        'rgba(54, 162, 235, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(255, 206, 86, 1)',
                    ],
                    'borderWidth' => 2,
                ],
            ],
        ]);
        $statsChart->setOptions([
            'responsive' => true,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
                'title' => [
                    'display' => true,
                    'text' => 'Répartition : Étudiants, Cours, Quiz',
                ],
            ],
        ]);

        // Create doughnut chart for certifications
        $certChart = $chartBuilder->createChart(Chart::TYPE_DOUGHNUT);
        $certChart->setData([
            'labels' => ['Réussies', 'Échouées'],
            'datasets' => [
                [
                    'data' => [$passedCertifications, $totalCertifications - $passedCertifications],
                    'backgroundColor' => [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(220, 53, 69, 0.8)',
                    ],
                    'borderColor' => [
                        'rgba(40, 167, 69, 1)',
                        'rgba(220, 53, 69, 1)',
                    ],
                    'borderWidth' => 2,
                ],
            ],
        ]);
        $certChart->setOptions([
            'responsive' => true,
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                ],
                'title' => [
                    'display' => true,
                    'text' => 'Certifications',
                ],
            ],
        ]);

        return $this->render('admin/stats/index.html.twig', [
            'studentsCount' => $studentsCount,
            'coursesCount' => $coursesCount,
            'quizzesCount' => $quizzesCount,
            'totalCertifications' => $totalCertifications,
            'passedCertifications' => $passedCertifications,
            'averageScore' => $averageScore,
            'certificationResults' => $certificationResults,
            'quizResults' => $quizResults,
            'statsChart' => $statsChart,
            'certChart' => $certChart,
        ]);
    }
}
