<?php

namespace App\Entity;

use App\Repository\FinancialGoalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FinancialGoalRepository::class)]
#[ORM\Table(name: 'financial_goal')]
class FinancialGoal
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $nom = '';

    #[ORM\Column(name: 'montant_cible')]
    private float $montantCible = 0;

    #[ORM\Column(name: 'montant_actuel')]
    private float $montantActuel = 0;

    #[ORM\Column(name: 'date_limite', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateLimite = null;

    #[ORM\Column(nullable: true)]
    private ?int $priorite = 3;

    #[ORM\ManyToOne(inversedBy: 'financialGoals')]
    #[ORM\JoinColumn(name: 'saving_account_id', referencedColumnName: 'id', nullable: false)]
    private ?SavingAccount $savingAccount = null;

    public function getId(): ?int { return $this->id; }

    public function getNom(): string { return $this->nom; }
    public function setNom(string $nom): static { $this->nom = $nom; return $this; }

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
