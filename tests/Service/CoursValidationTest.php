<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cours;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CoursValidationTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testValidCoursHasNoViolations(): void
    {
        $cours = (new Cours())
            ->setTitre('Introduction a la finance')
            ->setContenuTexte('Un contenu de cours suffisamment long pour etre valide.');

        $violations = $this->validator->validate($cours);

        self::assertCount(0, $violations);
    }

    public function testBlankTitleCreatesViolation(): void
    {
        $cours = (new Cours())
            ->setTitre('')
            ->setContenuTexte('Un contenu de cours suffisamment long pour etre valide.');

        $violations = $this->validator->validate($cours);

        self::assertGreaterThan(0, $violations->count());
    }

    public function testTooShortContentCreatesViolation(): void
    {
        $cours = (new Cours())
            ->setTitre('Cours OK')
            ->setContenuTexte('court');

        $violations = $this->validator->validate($cours);

        self::assertGreaterThan(0, $violations->count());
    }

    public function testTooLongTitleCreatesViolation(): void
    {
        $cours = (new Cours())
            ->setTitre(str_repeat('A', 151))
            ->setContenuTexte('Un contenu de cours suffisamment long pour etre valide.');

        $violations = $this->validator->validate($cours);

        self::assertGreaterThan(0, $violations->count());
    }
}
