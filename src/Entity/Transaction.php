<?php

namespace App\Entity;

use App\Repository\TransactionRepository;
use App\Entity\Expense;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TransactionRepository::class)]
class Transaction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    #[Assert\NotBlank(message: 'Transaction type is required.')]
    #[Assert\Choice(choices: ['EXPENSE', 'SAVING', 'INVESTMENT'], message: 'Transaction type is invalid.')]
    private string $type;

    #[ORM\Column]
    #[Assert\NotNull(message: 'Amount is required.')]
    #[Assert\Positive(message: 'Amount must be greater than 0.')]
    #[Assert\LessThanOrEqual(value: 1000000, message: 'Amount cannot exceed 1,000,000.')]
    private float $montant;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotNull(message: 'Date is required.')]
    #[Assert\LessThanOrEqual('today', message: 'Date cannot be in the future.')]
    private \DateTime $date;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'Description cannot exceed 500 characters.')]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, maxMessage: 'Source cannot exceed 50 characters.')]
    #[Assert\Regex(
        pattern: '/^[A-Za-z0-9 _.-]*$/',
        message: 'Source can contain only letters, numbers, spaces, dot, underscore and dash.'
    )]
    private ?string $moduleSource = null;

    #[ORM\ManyToOne(inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull(message: 'User is required.')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: Expense::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Expense $expense = null;

    public function __construct()
    {
        $this->date = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getMontant(): float
    {
        return $this->montant;
    }

    public function setMontant(float $montant): self
    {
        $this->montant = $montant;
        return $this;
    }

    public function getDate(): \DateTime
    {
        return $this->date;
    }

    public function setDate(\DateTime $date): self
    {
        $this->date = $date;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getModuleSource(): ?string
    {
        return $this->moduleSource;
    }

    public function setModuleSource(?string $moduleSource): self
    {
        $this->moduleSource = $moduleSource;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    public function getExpense(): ?Expense
    {
        return $this->expense;
    }

    public function setExpense(?Expense $expense): self
    {
        $this->expense = $expense;
        return $this;
    }
}
