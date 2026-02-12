<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\ExpenseRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use App\Service\CryptoApiService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[IsGranted('ROLE_ADMIN')]
#[Route('/user')]
final class UserController extends AbstractController
{
    #[Route('/', name: 'app_user_index', methods: ['GET'])]
public function index(UserRepository $userRepository): Response
{
    $users = $userRepository->findAll();
    /** @var User|null $currentUser */
    $currentUser = $this->getUser();

    $admins = [];
    $others = [];

    foreach ($users as $user) {
        $isCurrentUser = $currentUser !== null && $currentUser->getId() === $user->getId();
        if (in_array('ROLE_ADMIN', $user->getRoles(), true) || $isCurrentUser) {
            $admins[] = $user;
        } else {
            $others[] = $user;
        }
    }

    return $this->render('user/index.html.twig', [
        'admins' => $admins,
        'users' => $others,
        'current_admin_id' => $currentUser?->getId(),
    ]);
}

    #[Route('/new', name: 'app_user_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $user = new User();
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('password')->getData();
            if (trim($plainPassword) === '') {
                $this->addFlash('error', 'Password is required for a new user.');
                return $this->render('user/new.html.twig', [
                    'form' => $form,
                ]);
            }

            $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));

            /** @var UploadedFile $imageFile */
            $imageFile = $form->get('image')->getData();

            if ($imageFile) {
                $safeFilename = $slugger->slug(
                    pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME)
                );
                $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

                $imageFile->move(
                    $this->getParameter('user_images_directory'),
                    $newFilename
                );

                $user->setImage($newFilename);
            }

            $em->persist($user);
            $em->flush();

            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_show', methods: ['GET'])]
    public function show(
        User $user,
        TransactionRepository $transactionRepository,
        ExpenseRepository $expenseRepository,
        CryptoApiService $cryptoApiService
    ): Response
    {
        $transactions = $transactionRepository->findBy(['user' => $user], ['date' => 'DESC']);
        $revenues = $user->getRevenues()->toArray();
        $expenses = $expenseRepository->findBy(['user' => $user], ['expenseDate' => 'DESC']);

        $totalTransactions = count($transactions);
        $totalRevenues = array_sum(array_map(static fn($r) => (float) $r->getAmount(), $revenues));
        $totalExpenses = array_sum(array_map(static fn($e) => (float) $e->getAmount(), $expenses));

        $savingCount = count(array_filter($transactions, static fn($t) => $t->getType() === 'SAVING'));
        $expenseTxCount = count(array_filter($transactions, static fn($t) => $t->getType() === 'EXPENSE'));
        $investmentCount = count(array_filter($transactions, static fn($t) => $t->getType() === 'INVESTMENT'));
        $netCashFlow = $totalRevenues - $totalExpenses;
        $expenseRatio = $totalRevenues > 0 ? ($totalExpenses / $totalRevenues) * 100 : 0.0;
        $savingsRate = $totalRevenues > 0 ? (($totalRevenues - $totalExpenses) / $totalRevenues) * 100 : 0.0;

        $lastActivityDate = null;
        if (!empty($transactions)) {
            $lastActivityDate = $transactions[0]->getDate();
        }

        $monthLabels = [];
        $monthlyRevenue = array_fill(0, 6, 0.0);
        $monthlyExpense = array_fill(0, 6, 0.0);
        $monthMap = [];

        $monthCursor = new \DateTimeImmutable('first day of this month');
        $monthCursor = $monthCursor->modify('-5 months');
        for ($i = 0; $i < 6; $i++) {
            $key = $monthCursor->format('Y-m');
            $monthMap[$key] = $i;
            $monthLabels[] = $monthCursor->format('M Y');
            $monthCursor = $monthCursor->modify('+1 month');
        }

        foreach ($revenues as $revenue) {
            $key = $revenue->getReceivedAt()->format('Y-m');
            if (array_key_exists($key, $monthMap)) {
                $monthlyRevenue[$monthMap[$key]] += (float) $revenue->getAmount();
            }
        }

        foreach ($expenses as $expense) {
            if ($expense->getExpenseDate() === null) {
                continue;
            }
            $key = $expense->getExpenseDate()->format('Y-m');
            if (array_key_exists($key, $monthMap)) {
                $monthlyExpense[$monthMap[$key]] += (float) $expense->getAmount();
            }
        }

        $financialHealth = 'Good';
        if ($expenseRatio > 85) {
            $financialHealth = 'Critical';
        } elseif ($expenseRatio > 65) {
            $financialHealth = 'Moderate';
        }

        $insights = [
            sprintf('Expense ratio: %.1f%% of revenues.', $expenseRatio),
            sprintf('Savings rate: %.1f%%.', $savingsRate),
            $netCashFlow >= 0
                ? 'Positive net cash flow. Keep the current rhythm.'
                : 'Negative net cash flow. Consider reducing non-essential expenses.',
            $totalTransactions >= 20
                ? 'High activity user profile (20+ transactions).'
                : 'Low/medium activity user profile.',
        ];

        $apiPrices = [];
        $apiError = null;
        try {
            $apiPrices = $cryptoApiService->getPrices(['bitcoin', 'ethereum', 'tether']);
        } catch (\Throwable $e) {
            $apiError = 'Live API data is temporarily unavailable.';
        }

        return $this->render('user/show.html.twig', [
            'user' => $user,
            'stats' => [
                'total_transactions' => $totalTransactions,
                'total_revenues' => $totalRevenues,
                'total_expenses' => $totalExpenses,
                'saving_count' => $savingCount,
                'expense_tx_count' => $expenseTxCount,
                'investment_count' => $investmentCount,
                'last_activity_date' => $lastActivityDate,
                'net_cash_flow' => $netCashFlow,
                'expense_ratio' => $expenseRatio,
                'savings_rate' => $savingsRate,
                'financial_health' => $financialHealth,
            ],
            'chart_data' => [
                'labels' => $monthLabels,
                'revenues' => $monthlyRevenue,
                'expenses' => $monthlyExpense,
                'distribution' => [$savingCount, $expenseTxCount, $investmentCount],
            ],
            'insights' => $insights,
            'api_prices' => $apiPrices,
            'api_error' => $apiError,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_user_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        User $user,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        $form = $this->createForm(UserType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = (string) $form->get('password')->getData();
            if (trim($plainPassword) !== '') {
                $user->setPassword($passwordHasher->hashPassword($user, $plainPassword));
            }

            /** @var UploadedFile $imageFile */
            $imageFile = $form->get('image')->getData();

            if ($imageFile) {
                $safeFilename = $slugger->slug(
                    pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME)
                );
                $newFilename = $safeFilename.'-'.uniqid().'.'.$imageFile->guessExtension();

                $imageFile->move(
                    $this->getParameter('user_images_directory'),
                    $newFilename
                );

                $user->setImage($newFilename);
            }

            $em->flush();

            return $this->redirectToRoute('app_user_index');
        }

        return $this->render('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_user_delete', methods: ['POST'])]
    public function delete(Request $request, User $user, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $em->remove($user);
            $em->flush();
        }

        return $this->redirectToRoute('app_user_index');
    }
}
