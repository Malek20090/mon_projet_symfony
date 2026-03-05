<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class TransactionValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testValidTransactionHasNoViolations(): void
    {
        $transaction = $this->createValidTransaction();

        $violations = $this->validator->validate($transaction);

        self::assertCount(0, $violations);
    }

    public function testInvalidTypeCreatesViolation(): void
    {
        $transaction = $this->createValidTransaction()->setType('OTHER');

        $violations = $this->validator->validate($transaction);

        self::assertGreaterThan(0, $violations->count());
    }

    public function testNonPositiveAmountCreatesViolation(): void
    {
        $transaction = $this->createValidTransaction()->setMontant(0.0);

        $violations = $this->validator->validate($transaction);

        self::assertGreaterThan(0, $violations->count());
    }

    public function testFutureDateCreatesViolation(): void
    {
        $transaction = $this->createValidTransaction()
            ->setDate(new \DateTime('+2 days'));

        $violations = $this->validator->validate($transaction);

        self::assertGreaterThan(0, $violations->count());
    }

    public function testInvalidModuleSourceCreatesViolation(): void
    {
        $transaction = $this->createValidTransaction()
            ->setModuleSource('source@invalid');

        $violations = $this->validator->validate($transaction);

        self::assertGreaterThan(0, $violations->count());
    }

    private function createValidTransaction(): Transaction
    {
        $user = (new User())
            ->setEmail('validation@example.com')
            ->setPassword('secret123');

        return (new Transaction())
            ->setType('EXPENSE')
            ->setMontant(120.5)
            ->setDate(new \DateTime('today'))
            ->setDescription('Groceries')
            ->setModuleSource('manual-import')
            ->setUser($user);
    }
}

