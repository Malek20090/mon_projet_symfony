<?php

namespace App\Tests\Service;

use App\Entity\CasRelles;
use App\Entity\User;
use App\Service\CasRellesManager;
use PHPUnit\Framework\TestCase;

class CasRellesManagerTest extends TestCase
{
    public function testValidateReturnsTrueForValidCase(): void
    {
        $cas = $this->buildValidCase();
        $manager = new CasRellesManager();

        $this->assertTrue($manager->validate($cas));
    }

    public function testValidateThrowsWhenTitleIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le titre est obligatoire.');

        $cas = $this->buildValidCase();
        $cas->setTitre('   ');

        $manager = new CasRellesManager();
        $manager->validate($cas);
    }

    public function testValidateThrowsWhenAmountIsNotPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Le montant doit etre strictement positif.');

        $cas = $this->buildValidCase();
        $cas->setMontant(0.0);

        $manager = new CasRellesManager();
        $manager->validate($cas);
    }

    private function buildValidCase(): CasRelles
    {
        $user = new User();
        $user->setEmail('student@example.com');
        $user->setPassword('password123');

        $cas = new CasRelles();
        $cas->setUser($user);
        $cas->setTitre('Cas reel test');
        $cas->setType(CasRelles::TYPE_NEGATIF);
        $cas->setMontant(100.0);
        $cas->setSolution(CasRelles::SOLUTION_FAMILLE);
        $cas->setDateEffet(new \DateTime('today'));

        return $cas;
    }
}
