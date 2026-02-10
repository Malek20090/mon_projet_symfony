<?php

namespace App\Entity;

use App\Repository\FinancialGoalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: FinancialGoalRepository::class)]
#[ORM\Table(name: 'financial_goal')]
class FinancialGoal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: "Le nom du goal est obligatoire.")]
    #[Assert\Length(
        min: 3,
        max: 60,
        minMessage: "Le nom doit contenir au moins {{ limit }} caractères.",
        maxMessage: "Le nom ne doit pas dépasser {{ limit }} caractères."
    )]
    private string $nom = '';

    #[ORM\Column(name: 'montant_cible')]
    #[Assert\NotNull(message: "Le montant cible est obligatoire.")]
    #[Assert\Positive(message: "Le montant cible doit être strictement supérieur à 0.")]
    #[Assert\Range(
        min: 10,
        max: 1000000,
        notInRangeMessage: "Le montant cible doit être entre {{ min }} et {{ max }}."
    )]
    private float $montantCible = 0;

    #[ORM\Column(name: 'montant_actuel')]
    #[Assert\NotNull(message: "Le montant actuel est obligatoire.")]
    #[Assert\PositiveOrZero(message: "Le montant actuel ne peut pas être négatif.")]
    #[Assert\Expression(
        "this.getMontantActuel() <= this.getMontantCible()",
        message: "Le montant actuel ne peut pas dépasser le montant cible."
    )]
    private float $montantActuel = 0;

    #[ORM\Column(name: 'date_limite', type: Types::DATE_MUTABLE, nullable: true)]
    #[Assert\Type(type: \DateTimeInterface::class, message: "Date limite invalide.")]
    #[Assert\GreaterThanOrEqual("today", message: "La date limite doit être aujourd'hui ou dans le futur.")]
    private ?\DateTime $dateLimite = null;

    #[ORM\Column(nullable: true)]
    #[Assert\Choice(choices: [1,2,3,4,5], message: "La priorité doit être 1, 2, 3, 4 ou 5.")]
    private ?int $priorite = 3;

    #[ORM\ManyToOne(inversedBy: 'financialGoals')]
    #[ORM\JoinColumn(name: 'saving_account_id', referencedColumnName: 'id', nullable: false)]
    #[Assert\NotNull(message: "Le Saving Account est obligatoire.")]
    private ?SavingAccount $savingAccount = null;

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = trim($nom); return $this; }

    public function getMontantCible(): float { return $this->montantCible; }
    public function setMontantCible(float $montantCible): static { $this->montantCible = $montantCible; return $this; }

    public function getMontantActuel(): float { return $this->montantActuel; }
    public function setMontantActuel(float $montantActuel): static { $this->montantActuel = $montantActuel; return $this; }

    public function getDateLimite(): ?\DateTime { return $this->dateLimite; }
    public function setDateLimite(?\DateTime $dateLimite): static { $this->dateLimite = $dateLimite; return $this; }

    public function getPriorite(): ?int { return $this->priorite; }
    public function setPriorite(?int $priorite): static { $this->priorite = $priorite; return $this; }

    public function getSavingAccount(): ?SavingAccount { return $this->savingAccount; }
    public function setSavingAccount(?SavingAccount $savingAccount): static { $this->savingAccount = $savingAccount; return $this; }
}
