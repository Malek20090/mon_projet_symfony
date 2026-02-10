<?php

namespace App\Entity;

use App\Repository\QuizRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: QuizRepository::class)]
class Quiz
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $question = null;

    #[ORM\Column]
    private array $choixReponses = [];

    #[ORM\Column(length: 100)]
    private ?string $reponseCorrecte = null;

    #[ORM\Column]
    private ?int $pointsValeur = null;

    #[ORM\ManyToOne(inversedBy: 'quizzes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Cours $cours = null;

    #[ORM\ManyToOne(inversedBy: 'quizzes')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuestion(): ?string
    {
        return $this->question;
    }

    public function setQuestion(string $question): static
    {
        $this->question = $question;

        return $this;
    }

    public function getChoixReponses(): array
    {
        return $this->choixReponses;
    }

    public function setChoixReponses(array $choixReponses): static
    {
        $this->choixReponses = $choixReponses;

        return $this;
    }

    public function getReponseCorrecte(): ?string
    {
        return $this->reponseCorrecte;
    }

    public function setReponseCorrecte(string $reponseCorrecte): static
    {
        $this->reponseCorrecte = $reponseCorrecte;

        return $this;
    }

    public function getPointsValeur(): ?int
    {
        return $this->pointsValeur;
    }

    public function setPointsValeur(int $pointsValeur): static
    {
        $this->pointsValeur = $pointsValeur;

        return $this;
    }

    public function getCours(): ?Cours
    {
        return $this->cours;
    }

    public function setCours(?Cours $cours): static
    {
        $this->cours = $cours;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }
}
