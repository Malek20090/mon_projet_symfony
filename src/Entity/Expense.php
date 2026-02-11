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

    /**
     * Logical "category" field, stored in DB column "category".
     */
    #[ORM\Column(name: 'category', length: 100)]
    private ?string $category = null;

    /**
     * Logical "expenseDate" field, stored in DB column "expense_date".
     */
    #[ORM\Column(name: 'expense_date', type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $expenseDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

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

    public function getAmount(): ?float
    {
        return $this->amount;
    }

    public function setAmount(float $amount): static
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Backwards‑compatibility alias.
     */
    public function getMontant(): ?float
    {
        return $this->amount;
    }

    /**
     * Backwards‑compatibility alias.
     */
    public function setMontant(float $montant): static
    {
        $this->amount = $montant;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getExpenseDate(): ?\DateTimeInterface
    {
        return $this->expenseDate;
    }

    public function setExpenseDate(\DateTimeInterface $expenseDate): static
    {
        $this->expenseDate = $expenseDate;

        return $this;
    }

    /**
     * Backwards‑compatibility alias.
     */
    public function getDate(): ?\DateTimeInterface
    {
        return $this->expenseDate;
    }

    /**
     * Backwards‑compatibility alias.
     */
    public function setDate(\DateTimeInterface $date): static
    {
        $this->expenseDate = $date;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

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
