<?php

namespace App\Entity;

use App\Repository\ObjectifRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Component\Validator\Constraints as Assert;


use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[UniqueEntity(
    fields: ['name'],
    message: 'Un objectif avec ce nom existe déjà.'
)]
#[ORM\Entity(repositoryClass: ObjectifRepository::class)]
class Objectif
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    private ?string $name = null;


    #[ORM\Column]
    #[Assert\NotNull(message: 'Le multiplicateur est obligatoire.')]
    #[Assert\GreaterThanOrEqual(
    value: 1.00001,
    message: 'Le multiplicateur doit être supérieur ou égal à 1.01.'
    )]
private ?float $targetMultiplier = null;


    #[ORM\Column]
    private ?float $initialAmount = null;

    #[ORM\Column]
    private ?float $targetAmount = null;

    #[ORM\Column]
    private ?bool $isCompleted = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $createdAt = null;

    /**
     * @var Collection<int, Investissement>
     */
    #[ORM\OneToMany(targetEntity: Investissement::class, mappedBy: 'objectif')]
    #[Assert\Count(
    min: 1,
    minMessage: "Vous devez sélectionner au moins un investissement."
)]
private Collection $investissements;


    public function __construct()
    {
        $this->investissements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getTargetMultiplier(): ?float
    {
        return $this->targetMultiplier;
    }

    public function setTargetMultiplier(float $targetMultiplier): static
    {
        $this->targetMultiplier = $targetMultiplier;

        return $this;
    }

    public function getInitialAmount(): ?float
    {
        return $this->initialAmount;
    }

    public function setInitialAmount(float $initialAmount): static
    {
        $this->initialAmount = $initialAmount;

        return $this;
    }

    public function getTargetAmount(): ?float
    {
        return $this->targetAmount;
    }

    public function setTargetAmount(float $targetAmount): static
    {
        $this->targetAmount = $targetAmount;

        return $this;
    }

    public function isCompleted(): ?bool
    {
        return $this->isCompleted;
    }

    public function setIsCompleted(bool $isCompleted): static
    {
        $this->isCompleted = $isCompleted;

        return $this;
    }

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return Collection<int, Investissement>
     */
    public function getInvestissements(): Collection
    {
        return $this->investissements;
    }

    public function addInvestissement(Investissement $investissement): static
    {
        if (!$this->investissements->contains($investissement)) {
            $this->investissements->add($investissement);
            $investissement->setObjectif($this);
        }

        return $this;
    }

    public function removeInvestissement(Investissement $investissement): static
    {
        if ($this->investissements->removeElement($investissement)) {
            // set the owning side to null (unless already changed)
            if ($investissement->getObjectif() === $this) {
                $investissement->setObjectif(null);
            }
        }

        return $this;
    }
}
