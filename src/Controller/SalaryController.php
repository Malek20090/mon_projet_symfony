<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\SalaryProfileType;
use App\Repository\ExpenseRepository;
use App\Repository\RevenueRepository;
use App\Repository\TransactionRepository;
use App\Service\SalaryExpenseAiService;
use App\Repository\UserBehaviorProfileRepository;
use App\Service\UserBehaviorAiNarratorService;
use App\Service\UserBehaviorScoringService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_SALARY')]
class SalaryController extends AbstractController
{
    #[Route('/salary', name: 'salary_dashboard')]
    public function index(): Response
    {
        /** @var User|null $currentUser */
        $currentUser = $this->getUser();

        return $this->render('salary/index.html.twig', [
            'current_user' => $currentUser,
        ]);
    }

    #[Route('/salary/profile', name: 'salary_profile', methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        UserPasswordHasherInterface $passwordHasher,
        TransactionRepository $transactionRepository,
        RevenueRepository $revenueRepository,
        ExpenseRepository $expenseRepository,
        SalaryExpenseAiService $salaryExpenseAiService,
        UserBehaviorScoringService $behaviorScoringService,
        UserBehaviorAiNarratorService $behaviorAiNarratorService,
        UserBehaviorProfileRepository $userBehaviorProfileRepository
    ): Response {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User session is required.');
        }

        $form = $this->createForm(SalaryProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('plainPassword')->getData();
            if (trim($plainPassword) !== '') {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            }

            /** @var UploadedFile|null $imageFile */
            $imageFile = $form->get('image')->getData();
            if ($imageFile) {
                $safeFilename = $slugger->slug(pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME));
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $imageFile->guessExtension();

                $imageFile->move(
                    $this->getParameter('user_images_directory'),
                    $newFilename
                );

                $user->setImage($newFilename);
            }

            $em->flush();
            $this->addFlash('success', 'Profile updated successfully.');

            return $this->redirectToRoute('salary_profile');
        }

        $transactions = $transactionRepository->findBy(
            ['user' => $user],
            ['date' => 'DESC']
        );
        $revenues = array_values(array_filter(
            $revenueRepository->findBy(['user' => $user], ['receivedAt' => 'DESC']),
            static fn ($revenue) => $revenue->getUser()->getId() === $user->getId()
        ));
        $expenses = array_values(array_filter(
            $expenseRepository->findBy(['user' => $user], ['expenseDate' => 'DESC']),
            static fn ($expense) => $expense->getUser()?->getId() === $user->getId()
        ));
        $aiInsights = $salaryExpenseAiService->buildInsights($revenues, $expenses);

        try {
            $existingBehaviorProfile = $userBehaviorProfileRepository->findOneByUser($user);
            $behaviorSnapshot = $behaviorScoringService->buildSnapshot(
                $user,
                $transactions,
                $existingBehaviorProfile
            );

            if ($existingBehaviorProfile === null) {
                $em->persist($behaviorSnapshot['entity']);
            }
            $em->flush();
        } catch (\Throwable) {
            $behaviorSnapshot = $behaviorScoringService->buildSnapshot($user, $transactions);
        }

        $narrationContext = [
            'score' => $behaviorSnapshot['entity']->getScore(),
            'profile_type' => $behaviorSnapshot['entity']->getProfileType(),
            'strengths' => $behaviorSnapshot['entity']->getStrengths(),
            'weaknesses' => $behaviorSnapshot['entity']->getWeaknesses(),
            'next_actions' => $behaviorSnapshot['entity']->getNextActions(),
            'metrics' => $behaviorSnapshot['metrics'],
            'week_tracking' => $behaviorSnapshot['week_tracking'],
            'score_delta' => $behaviorSnapshot['score_delta'],
        ];

        $behaviorAiNarration = $behaviorAiNarratorService->narrate($narrationContext);
        if (!$behaviorAiNarration['ok']) {
            $behaviorAiNarration = [
                'ok' => true,
                'source' => 'local',
                'text' => $behaviorAiNarratorService->buildLocalFallback($narrationContext),
                'model' => null,
                'error' => $behaviorAiNarration['error'],
            ];
        }

        return $this->render('salary/profile.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
            'transactions' => $transactions,
            'aiInsights' => $aiInsights,
            'behavior_profile' => $behaviorSnapshot['entity'],
            'behavior_metrics' => $behaviorSnapshot['metrics'],
            'behavior_week_tracking' => $behaviorSnapshot['week_tracking'],
            'behavior_score_delta' => $behaviorSnapshot['score_delta'],
            'behavior_ai_narration' => $behaviorAiNarration,
        ]);
    }

    #[Route('/salary/profile/export/csv', name: 'salary_profile_export_csv', methods: ['GET'])]
    public function exportCsv(TransactionRepository $transactionRepository): StreamedResponse
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User session is required.');
        }

        $transactions = $transactionRepository->findBy(
            ['user' => $user],
            ['date' => 'DESC', 'id' => 'DESC']
        );

        $filename = sprintf(
            'salary_statement_%s_%s.csv',
            $user->getId(),
            (new \DateTimeImmutable())->format('Ymd_His')
        );

        $response = new StreamedResponse(function () use ($transactions, $user): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Decide$ Bank Statement']);
            fputcsv($out, ['Account Holder', (string) ($user->getNom() ?: $user->getEmail())]);
            fputcsv($out, ['Email', (string) $user->getEmail()]);
            fputcsv($out, ['Exported At', (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
            fputcsv($out, []);
            fputcsv($out, ['#', 'Date', 'Type', 'Description', 'Amount (TND)']);

            foreach ($transactions as $index => $transaction) {
                fputcsv($out, [
                    $index + 1,
                    $transaction->getDate()?->format('Y-m-d') ?? '',
                    $transaction->getType(),
                    (string) ($transaction->getDescription() ?? ''),
                    number_format((float) $transaction->getMontant(), 2, '.', ''),
                ]);
            }

            fclose($out);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));

        return $response;
    }

    #[Route('/salary/profile/export/pdf', name: 'salary_profile_export_pdf', methods: ['GET'])]
    public function exportPdf(TransactionRepository $transactionRepository): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User session is required.');
        }

        $transactions = $transactionRepository->findBy(
            ['user' => $user],
            ['date' => 'ASC', 'id' => 'ASC']
        );

        $closingBalance = (float) $user->getSoldeTotal();
        $signedTotal = 0.0;
        foreach ($transactions as $transaction) {
            $signedTotal += $this->toSignedAmount((string) $transaction->getType(), (float) $transaction->getMontant());
        }
        $openingBalance = $closingBalance - $signedTotal;

        $running = $openingBalance;
        $rows = [];
        foreach ($transactions as $transaction) {
            $signedAmount = $this->toSignedAmount((string) $transaction->getType(), (float) $transaction->getMontant());
            $running += $signedAmount;
            $rows[] = [
                'date' => $transaction->getDate()?->format('Y-m-d') ?? '',
                'type' => $transaction->getType(),
                'description' => (string) ($transaction->getDescription() ?? ''),
                'signed_amount' => $signedAmount,
                'running_balance' => $running,
            ];
        }

        $html = $this->renderView('salary/statement_pdf.html.twig', [
            'user' => $user,
            'rows' => $rows,
            'openingBalance' => $openingBalance,
            'closingBalance' => $closingBalance,
            'generatedAt' => new \DateTimeImmutable(),
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setIsRemoteEnabled(true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = sprintf(
            'salary_statement_%s_%s.pdf',
            $user->getId(),
            (new \DateTimeImmutable())->format('Ymd_His')
        );

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function toSignedAmount(string $type, float $amount): float
    {
        $normalized = strtoupper(trim($type));
        if ($normalized === 'EXPENSE' || $normalized === 'INVESTMENT') {
            return -abs($amount);
        }

        return abs($amount);
    }
}
