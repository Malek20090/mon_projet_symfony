<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cours;
use App\Entity\Quiz;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class CoursTest extends TestCase
{
    public function testSettersAndGetters(): void
    {
        $user = (new User())
            ->setEmail('teacher@example.com')
            ->setPassword('password-123');

        $cours = (new Cours())
            ->setTitre('Introduction a la bourse')
            ->setContenuTexte('Contenu du cours')
            ->setTypeMedia('VIDEO')
            ->setUrlMedia('https://example.com/video')
            ->setUser($user);

        self::assertSame('Introduction a la bourse', $cours->getTitre());
        self::assertSame('Contenu du cours', $cours->getContenuTexte());
        self::assertSame('VIDEO', $cours->getTypeMedia());
        self::assertSame('https://example.com/video', $cours->getUrlMedia());
        self::assertSame($user, $cours->getUser());
    }

    public function testAddAndRemoveQuizKeepBothSidesInSync(): void
    {
        $cours = new Cours();
        $quiz = new Quiz();

        $cours->addQuiz($quiz);

        self::assertCount(1, $cours->getQuizzes());
        self::assertSame($cours, $quiz->getCours());

        $cours->removeQuiz($quiz);

        self::assertCount(0, $cours->getQuizzes());
        self::assertNull($quiz->getCours());
    }
}

