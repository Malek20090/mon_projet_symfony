<?php

namespace App\Controller;

use App\Repository\UserRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ReportController extends AbstractController
{
    /* =====================================================
       📊 PAGE REPORTS (STATS + GRAPHIQUES)
    ===================================================== */
    #[Route('/reports', name: 'app_reports')]
    public function index(
        UserRepository $userRepository,
        TransactionRepository $transactionRepository,
        EntityManagerInterface $em
    ): Response {

        /* =========================
           📊 GLOBAL STATS
        ========================= */

        $totalUsers = $userRepository->count([]);
        $totalTransactions = $transactionRepository->count([]);

        $totalSavings = $em->createQuery(
            "SELECT COALESCE(SUM(t.montant), 0)
             FROM App\Entity\Transaction t
             WHERE t.type = 'SAVING'"
        )->getSingleScalarResult();

        $totalExpenses = $em->createQuery(
            "SELECT COALESCE(SUM(t.montant), 0)
             FROM App\Entity\Transaction t
             WHERE t.type = 'EXPENSE'"
        )->getSingleScalarResult();

        $totalInvestments = $em->createQuery(
            "SELECT COALESCE(SUM(t.montant), 0)
             FROM App\Entity\Transaction t
             WHERE t.type = 'INVESTMENT'"
        )->getSingleScalarResult();

        /* =========================
           📈 MONTHLY STATS (SQL NATIF)
        ========================= */

        $conn = $em->getConnection();

        $sql = "
            SELECT 
                MONTH(date) AS month,
                SUM(CASE WHEN type = 'SAVING' THEN montant ELSE 0 END) AS savings,
                SUM(CASE WHEN type = 'EXPENSE' THEN montant ELSE 0 END) AS expenses
            FROM transaction
            GROUP BY month
            ORDER BY month ASC
        ";

        $monthlyResults = $conn->executeQuery($sql)->fetchAllAssociative();

        $months = [];
        $monthlySavings = [];
        $monthlyExpenses = [];

        $monthNames = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Aug',
            9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'
        ];

        foreach ($monthlyResults as $row) {
            $months[] = $monthNames[(int)$row['month']];
            $monthlySavings[] = (float) $row['savings'];
            $monthlyExpenses[] = (float) $row['expenses'];
        }

        return $this->render('reports/index.html.twig', [
            'totalUsers' => $totalUsers,
            'totalTransactions' => $totalTransactions,
            'totalSavings' => $totalSavings,
            'totalExpenses' => $totalExpenses,
            'totalInvestments' => $totalInvestments,
            'months' => $months,
            'monthlySavings' => $monthlySavings,
            'monthlyExpenses' => $monthlyExpenses,
        ]);
    }

    /* =====================================================
       📄 EXPORT PDF (GRAPHIQUES INCLUS)
    ===================================================== */
    #[Route('/reports/export/pdf', name: 'app_reports_pdf', methods: ['POST'])]
    public function exportPdf(Request $request): Response
    {
        // 🔹 Récupérer l’image du graphique
        $chartImage = $request->request->get('chartImage');

        // 🔹 Config Dompdf
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);

        // 🔹 HTML du PDF
        $html = $this->renderView('reports/pdf.html.twig', [
            'chartImage' => $chartImage
        ]);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="reports.pdf"',
            ]
        );
    }
}
