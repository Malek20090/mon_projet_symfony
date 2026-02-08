<?php

namespace App\Entity;

use App\Repository\SavingAccountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavingAccountRepository::class)]
class SavingAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?float $sold = null;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $dateCreation = null;

    #[ORM\Column(nullable: true)]
    private ?float $tauxInteret = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?self $user = null;

    /**
     * @var Collection<int, FinancialGoal>
     */
    #[ORM\OneToMany(targetEntity: FinancialGoal::class, mappedBy: 'savingAccount')]
    private Collection $no;

    public function __construct()
    {
        $this->no = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSold(): ?float
    {
        return $this->sold;
    }

    public function setSold(?float $sold): static
    {
        $this->sold = $sold;

        return $this;
    }

    public function getDateCreation(): ?\DateTime
    {
        return $this->dateCreation;
    }

    public function setDateCreation(?\DateTime $dateCreation): static
    {
        $this->dateCreation = $dateCreation;

        return $this;
    }

    public function getTauxInteret(): ?float
    {
        return $this->tauxInteret;
    }

    public function setTauxInteret(?float $tauxInteret): static
    {
        $this->tauxInteret = $tauxInteret;

        return $this;
    }

    public function getUser(): ?self
    {
        return $this->user;
    }

    public function setUser(?self $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * @return Collection<int, FinancialGoal>
     */
    public function getNo(): Collection
    {
        return $this->no;
    }

    public function addNo(FinancialGoal $no): static
    {
        if (!$this->no->contains($no)) {
            $this->no->add($no);
            $no->setSavingAccount($this);
        }

        return $this;
    }

    public function removeNo(FinancialGoal $no): static
    {
        if ($this->no->removeElement($no)) {
            // set the owning side to null (unless already changed)
            if ($no->getSavingAccount() === $this) {
                $no->setSavingAccount(null);
            }
        }

        return $this;
    }
}
