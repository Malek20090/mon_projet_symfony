<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Transaction;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TransactionTest extends TestCase
{
    public function testConstructorInitializesDate(): void
    {
        $transaction = new Transaction();

        self::assertInstanceOf(\DateTime::class, $transaction->getDate());
    }

    public function testSettersAndGetters(): void
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('password-123');

        $date = new \DateTime('2026-02-20');

        $transaction = (new Transaction())
            ->setType('EXPENSE')
            ->setMontant(99.5)
            ->setDate($date)
            ->setDescription('Achat materiel')
            ->setModuleSource('manual')
            ->setUser($user);

        self::assertSame('EXPENSE', $transaction->getType());
        self::assertSame(99.5, $transaction->getMontant());
        self::assertSame($date, $transaction->getDate());
        self::assertSame('Achat materiel', $transaction->getDescription());
        self::assertSame('manual', $transaction->getModuleSource());
        self::assertSame($user, $transaction->getUser());
    }
}
