<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Transaction;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    public function testSetEmailNormalizesToLowercase(): void
    {
        $user = new User();

        $user->setEmail('TEST@Example.COM');

        self::assertSame('test@example.com', $user->getEmail());
    }

    public function testRolesAlwaysContainRoleUserWithoutDuplicates(): void
    {
        $user = new User();
        $user->setRoles(['ROLE_ADMIN', 'ROLE_USER']);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testAddAndRemoveTransactionKeepBothSidesInSync(): void
    {
        $user = new User();
        $transaction = (new Transaction())
            ->setType('SAVING')
            ->setMontant(100);

        $user->addTransaction($transaction);

        self::assertCount(1, $user->getTransactions());
        self::assertSame($user, $transaction->getUser());

        $user->removeTransaction($transaction);

        self::assertCount(0, $user->getTransactions());
        self::assertNull($transaction->getUser());
    }

    public function testRecalculateSoldeUsesExpenseAndSavingTypes(): void
    {
        $user = new User();

        $saving = (new Transaction())->setType('SAVING')->setMontant(250);
        $expense = (new Transaction())->setType('EXPENSE')->setMontant(40);
        $investment = (new Transaction())->setType('INVESTMENT')->setMontant(1000);

        $user->addTransaction($saving);
        $user->addTransaction($expense);
        $user->addTransaction($investment);

        $user->recalculateSolde();

        self::assertSame(210.0, $user->getSoldeTotal());
    }

    public function testToStringWithNomAndEmail(): void
    {
        $user = new User();
        $user->setNom('Malek');
        $user->setEmail('malek@example.com');

        self::assertSame('Malek (malek@example.com)', (string) $user);
    }
}
