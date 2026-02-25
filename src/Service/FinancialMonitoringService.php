<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Expense;
use App\Entity\Revenue;
use App\Entity\User;
use App\Repository\ExpenseRepository;
use App\Repository\RevenueRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class FinancialMonitoringService
{
    public function __construct(
        private readonly RevenueRepository $revenueRepository,
        private readonly ExpenseRepository $expenseRepository,
        private readonly FinancialAlertMailerService $financialAlertMailerService,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Calculate totals and trigger email alerts based on business thresholds.
     *
     * @return array{totalIncome: float, totalExpenses: float, netBalance: float, expenseRatio: float|null}
     */
    public function evaluateAndNotify(User $user, bool $sendMonthlySummary = false): array
    {
        /** @var Revenue[] $revenues */
        $revenues = $this->revenueRepository->findBy(['user' => $user]);
        /** @var Expense[] $expenses */
        $expenses = $this->expenseRepository->findBy(['user' => $user]);

        $totalIncome = array_sum(array_map(static fn (Revenue $r): float => (float) $r->getAmount(), $revenues));
        $totalExpenses = array_sum(array_map(static fn (Expense $e): float => (float) ($e->getAmount() ?? 0.0), $expenses));
        $netBalance = $totalIncome - $totalExpenses;
        $expenseRatio = $totalIncome > 0 ? ($totalExpenses / $totalIncome) : null;

        $this->logger->info('Finance monitor totals.', [
            'userId' => $user->getId(),
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'netBalance' => $netBalance,
            'expenseRatio' => $expenseRatio,
            'sendMonthlySummary' => $sendMonthlySummary,
        ]);

        $this->dispatchOverspendingAlertIfNeeded($user, $totalIncome, $totalExpenses, $expenseRatio);

        if ($sendMonthlySummary) {
            $this->dispatchMonthlySummaryIfNeeded($user, $totalIncome, $totalExpenses, $netBalance);
        }

        return [
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'netBalance' => $netBalance,
            'expenseRatio' => $expenseRatio,
        ];
    }

    /**
     * Return the current alert status without sending emails.
     *
     * @return array{
     *   totalIncome: float,
     *   totalExpenses: float,
     *   netBalance: float,
     *   expenseRatio: float|null,
     *   alertLevel: string|null,
     *   overspendingSentToday: bool,
     *   monthlySummarySentThisMonth: bool
     * }
     */
    public function getAlertStatus(User $user): array
    {
        /** @var Revenue[] $revenues */
        $revenues = $this->revenueRepository->findBy(['user' => $user]);
        /** @var Expense[] $expenses */
        $expenses = $this->expenseRepository->findBy(['user' => $user]);

        $totalIncome = array_sum(array_map(static fn (Revenue $r): float => (float) $r->getAmount(), $revenues));
        $totalExpenses = array_sum(array_map(static fn (Expense $e): float => (float) ($e->getAmount() ?? 0.0), $expenses));
        $netBalance = $totalIncome - $totalExpenses;
        $expenseRatio = $totalIncome > 0 ? ($totalExpenses / $totalIncome) : null;

        $alertLevel = null;
        if ($totalIncome > 0 && $expenseRatio !== null && $expenseRatio > 1.0) {
            $alertLevel = 'critical';
        } elseif ($totalIncome > 0 && $expenseRatio !== null && $expenseRatio > 0.8) {
            $alertLevel = 'warning';
        } elseif ($totalIncome <= 0 && $totalExpenses > 0) {
            $alertLevel = 'critical';
        }

        $dailyKey = sprintf(
            'finance_alert_%d_%s_%s',
            (int) $user->getId(),
            $alertLevel ?? 'none',
            (new \DateTimeImmutable())->format('Ymd')
        );
        $monthlyKey = sprintf(
            'finance_monthly_summary_%d_%s',
            (int) $user->getId(),
            (new \DateTimeImmutable())->format('Y-m')
        );

        $overspendingSentToday = (bool) $this->cache->get($dailyKey, static function (ItemInterface $item): bool {
            $item->expiresAfter(1);
            return false;
        });
        $monthlySummarySentThisMonth = (bool) $this->cache->get($monthlyKey, static function (ItemInterface $item): bool {
            $item->expiresAfter(1);
            return false;
        });

        return [
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            'netBalance' => $netBalance,
            'expenseRatio' => $expenseRatio,
            'alertLevel' => $alertLevel,
            'overspendingSentToday' => $overspendingSentToday,
            'monthlySummarySentThisMonth' => $monthlySummarySentThisMonth,
        ];
    }

    private function dispatchOverspendingAlertIfNeeded(User $user, float $totalIncome, float $totalExpenses, ?float $expenseRatio): void
    {
        $alertLevel = null;
        if ($totalIncome > 0 && $expenseRatio !== null && $expenseRatio > 1.0) {
            $alertLevel = 'critical';
        } elseif ($totalIncome > 0 && $expenseRatio !== null && $expenseRatio > 0.8) {
            $alertLevel = 'warning';
        } elseif ($totalIncome <= 0 && $totalExpenses > 0) {
            $alertLevel = 'critical';
        }

        if ($alertLevel === null) {
            $this->logger->info('Overspending alert skipped (no threshold exceeded).', [
                'userId' => $user->getId(),
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'expenseRatio' => $expenseRatio,
            ]);
            return;
        }

        $cacheKey = sprintf(
            'finance_alert_%d_%s_%s',
            (int) $user->getId(),
            $alertLevel,
            (new \DateTimeImmutable())->format('Ymd')
        );

        $alreadySentToday = $this->cache->get($cacheKey, static function (ItemInterface $item): bool {
            $item->expiresAfter(86400);
            return false;
        });

        if ($alreadySentToday) {
            $this->logger->info('Overspending alert skipped (already sent today).', [
                'userId' => $user->getId(),
                'alertLevel' => $alertLevel,
                'cacheKey' => $cacheKey,
            ]);
            return;
        }

        try {
            $this->financialAlertMailerService->sendOverspendingAlert($totalIncome, $totalExpenses);
            $this->logger->info('Overspending alert sent.', [
                'userId' => $user->getId(),
                'alertLevel' => $alertLevel,
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'expenseRatio' => $expenseRatio,
            ]);
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, static function (ItemInterface $item): bool {
                $item->expiresAfter(86400);
                return true;
            });
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to send overspending alert email.', [
                'userId' => $user->getId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function dispatchMonthlySummaryIfNeeded(User $user, float $totalIncome, float $totalExpenses, float $netBalance): void
    {
        $cacheKey = sprintf(
            'finance_monthly_summary_%d_%s',
            (int) $user->getId(),
            (new \DateTimeImmutable())->format('Y-m')
        );

        $alreadySentThisMonth = $this->cache->get($cacheKey, static function (ItemInterface $item): bool {
            $item->expiresAfter(2678400);
            return false;
        });

        if ($alreadySentThisMonth) {
            $this->logger->info('Monthly summary skipped (already sent this month).', [
                'userId' => $user->getId(),
                'cacheKey' => $cacheKey,
            ]);
            return;
        }

        try {
            $this->financialAlertMailerService->sendMonthlySummary($totalIncome, $totalExpenses, $netBalance);
            $this->logger->info('Monthly summary sent.', [
                'userId' => $user->getId(),
                'totalIncome' => $totalIncome,
                'totalExpenses' => $totalExpenses,
                'netBalance' => $netBalance,
            ]);
            $this->cache->delete($cacheKey);
            $this->cache->get($cacheKey, static function (ItemInterface $item): bool {
                $item->expiresAfter(2678400);
                return true;
            });
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to send monthly summary email.', [
                'userId' => $user->getId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Clear cached mailer locks so alerts can be re-sent.
     */
    public function clearAlertCache(User $user): void
    {
        $today = (new \DateTimeImmutable())->format('Ymd');
        foreach (['critical', 'warning', 'none'] as $level) {
            $dailyKey = sprintf('finance_alert_%d_%s_%s', (int) $user->getId(), $level, $today);
            $this->cache->delete($dailyKey);
        }

        $monthlyKey = sprintf(
            'finance_monthly_summary_%d_%s',
            (int) $user->getId(),
            (new \DateTimeImmutable())->format('Y-m')
        );
        $this->cache->delete($monthlyKey);
    }
}
