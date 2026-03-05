<?php

namespace App\Tests\Service;

use PHPUnit\Framework\TestCase;
use App\Entity\Investissement;
use App\Service\InvestissementValidator;

class InvestissementValidatorTest extends TestCase
{

    public function testValidInvestissement()
    {
        $investissement = new Investissement();
        $investissement->setAmountInvested(500);
        $investissement->setQuantity(0.01);
        $investissement->setBuyPrice(50000);

        $validator = new InvestissementValidator();

        $this->assertTrue($validator->validate($investissement));
    }

    public function testInvestissementWithNegativeAmount()
    {
        $this->expectException(\InvalidArgumentException::class);

        $investissement = new Investissement();
        $investissement->setAmountInvested(-100);
        $investissement->setQuantity(0.01);
        $investissement->setBuyPrice(50000);

        $validator = new InvestissementValidator();
        $validator->validate($investissement);
    }

    public function testInvestissementWithInvalidQuantity()
    {
        $this->expectException(\InvalidArgumentException::class);

        $investissement = new Investissement();
        $investissement->setAmountInvested(100);
        $investissement->setQuantity(0);
        $investissement->setBuyPrice(50000);

        $validator = new InvestissementValidator();
        $validator->validate($investissement);
    }

    public function testInvestissementWithInvalidPrice()
    {
        $this->expectException(\InvalidArgumentException::class);

        $investissement = new Investissement();
        $investissement->setAmountInvested(100);
        $investissement->setQuantity(0.01);
        $investissement->setBuyPrice(0);

        $validator = new InvestissementValidator();
        $validator->validate($investissement);
    }

}