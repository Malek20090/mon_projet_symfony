<?php

namespace App\Entity;

use App\Repository\ExpenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[ORM\Entity(repositoryClass: ExpenseRepository::class)]
class Expense
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::FLOAT)]
    #[Assert\NotNull(message: 'Le montant est obligatoire.')]
    #[Assert\PositiveOrZero(message: 'Le montant doit être positif.')]
    private ?float $amount = null;

    #[ORM\Column(length: 100)]
    private ?string $categorie = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $date = null;

    #[ORM\ManyToOne(targetEntity: Revenue::class, inversedBy: 'expenses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Revenue $revenue = null;

    #[Assert\Callback]
    public function validateAmountNotGreaterThanRevenue(ExecutionContextInterface $context): void
    {
        if ($this->revenue === null || $this->amount === null) {
            return;
        }

        if ($this->amount > $this->revenue->getAmount()) {
            $context->buildViolation('Le montant de la dépense ne doit pas dépasser le revenu sélectionné (%limit% €).')
                ->atPath('amount')
                ->setParameter('%limit%', (string) $this->revenue->getAmount())
                ->addViolation();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMontant(): ?float
    {
        return $this->montant;
    }

    public function setMontant(float $montant): static
    {
        $this->montant = $montant;

        return $this;
    }

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(string $categorie): static
    {
        $this->categorie = $categorie;

        return $this;
    }

    public function getDate(): ?\DateTime
    {
        return $this->date;
    }

    public function setDate(\DateTime $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getRevenue(): ?Revenue
    {
        return $this->revenue;
    }

    public function setRevenue(?Revenue $revenue): static
    {
        $this->revenue = $revenue;

        return $this;
    }
}
