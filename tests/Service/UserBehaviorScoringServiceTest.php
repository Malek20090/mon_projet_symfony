<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\User;
use App\Service\UserBehaviorScoringService;
use PHPUnit\Framework\TestCase;

final class UserBehaviorScoringServiceTest extends TestCase
{
    public function testBuildSnapshotReturnsExpectedStructure(): void
    {
        $user = (new User())
            ->setEmail('profile@example.com')
            ->setPassword('secret123');

        $transactions = [
            $this->createTransaction('SAVING', 400, '-5 days'),
            $this->createTransaction('EXPENSE', 120, '-4 days'),
            $this->createTransaction('EXPENSE', 80, '-12 days'),
            $this->createTransaction('SAVING', 350, '-35 days'),
            $this->createTransaction('EXPENSE', 95, '-42 days'),
        ];

        $service = new UserBehaviorScoringService();
        $snapshot = $service->buildSnapshot($user, $transactions);

        self::assertArrayHasKey('entity', $snapshot);
        self::assertArrayHasKey('metrics', $snapshot);
        self::assertArrayHasKey('week_tracking', $snapshot);
        self::assertArrayHasKey('score_delta', $snapshot);

        self::assertSame($user, $snapshot['entity']->getUser());
        self::assertGreaterThanOrEqual(0, $snapshot['entity']->getScore());
        self::assertLessThanOrEqual(100, $snapshot['entity']->getScore());
        self::assertCount(3, $snapshot['entity']->getNextActions());
    }

    public function testBuildSnapshotWithNoTransactionsGivesFallbackProfile(): void
    {
        $user = (new User())
            ->setEmail('empty@example.com')
            ->setPassword('secret123');

        $service = new UserBehaviorScoringService();
        $snapshot = $service->buildSnapshot($user, []);

        self::assertSame(50, $snapshot['entity']->getScore());
        self::assertSame('Insufficient Data', $snapshot['entity']->getProfileType());
        self::assertCount(3, $snapshot['entity']->getNextActions());
    }

    public function testBuildSnapshotIgnoresTransactionsOlderThan90Days(): void
    {
        $user = (new User())
            ->setEmail('old-data@example.com')
            ->setPassword('secret123');

        $transactions = [
            $this->createTransaction('EXPENSE', 300, '-120 days'),
            $this->createTransaction('SAVING', 500, '-180 days'),
        ];

        $service = new UserBehaviorScoringService();
        $snapshot = $service->buildSnapshot($user, $transactions);

        self::assertSame(50, $snapshot['entity']->getScore());
        self::assertSame('Insufficient Data', $snapshot['entity']->getProfileType());
        self::assertSame(0.0, $snapshot['week_tracking']['current_week_expense']);
        self::assertSame(0.0, $snapshot['week_tracking']['previous_week_expense']);
        self::assertSame(0, $snapshot['week_tracking']['expense_delta_pct']);
    }

    private function createTransaction(string $type, float $amount, string $relativeDate): Transaction
    {
        return (new Transaction())
            ->setType($type)
            ->setMontant($amount)
            ->setDate(new \DateTime($relativeDate));
    }
}
