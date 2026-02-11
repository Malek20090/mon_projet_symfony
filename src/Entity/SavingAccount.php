<?php

namespace App\Entity;

use App\Repository\SavingAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavingAccountRepository::class)]
#[ORM\Table(name: 'saving_account')]
class SavingAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?float $sold = 0;

    #[ORM\Column(name: 'date_creation', type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateCreation = null;

    #[ORM\Column(name: 'taux_interet', nullable: true)]
    private ?float $tauxInteret = 0;

    // NOTE: we reference your existing User entity
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false)]
    private ?User $user = null;

    /** @var Collection<int, FinancialGoal> */
    #[ORM\OneToMany(mappedBy: 'savingAccount', targetEntity: FinancialGoal::class, orphanRemoval: true)]
    private Collection $financialGoals;

    public function __construct()
    {
        $this->financialGoals = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }

    public function getSold(): ?float { return $this->sold; }
    public function setSold(?float $sold): static { $this->sold = $sold; return $this; }

    public function getDateCreation(): ?\DateTime { return $this->dateCreation; }
    public function setDateCreation(?\DateTime $dateCreation): static { $this->dateCreation = $dateCreation; return $this; }

    public function getTauxInteret(): ?float { return $this->tauxInteret; }
    public function setTauxInteret(?float $tauxInteret): static { $this->tauxInteret = $tauxInteret; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    /** @return Collection<int, FinancialGoal> */
    public function getFinancialGoals(): Collection { return $this->financialGoals; }

    public function addFinancialGoal(FinancialGoal $goal): static
    {
        if (!$this->financialGoals->contains($goal)) {
            $this->financialGoals->add($goal);
            $goal->setSavingAccount($this);
        }
        return $this;
    }

    public function removeFinancialGoal(FinancialGoal $goal): static
    {
        if ($this->financialGoals->removeElement($goal)) {
            if ($goal->getSavingAccount() === $this) {
                $goal->setSavingAccount(null);
            }
        }
        return $this;
    }
}
