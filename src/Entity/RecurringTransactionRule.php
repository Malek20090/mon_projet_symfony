<?php

namespace App\Entity;

use App\Repository\RecurringTransactionRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RecurringTransactionRuleRepository::class)]
#[ORM\Table(name: 'recurring_transaction_rule')]
#[ORM\UniqueConstraint(name: 'uniq_recurring_user_signature', columns: ['user_id', 'signature'])]
class RecurringTransactionRule
{
    public const KIND_REVENUE = 'REVENUE';
    public const KIND_EXPENSE = 'EXPENSE';

    public const FREQ_WEEKLY = 'WEEKLY';
    public const FREQ_MONTHLY = 'MONTHLY';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 10)]
    private string $kind = self::KIND_EXPENSE;

    #[ORM\Column(length: 20)]
    private string $frequency = self::FREQ_MONTHLY;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(length: 255)]
    private string $label = '';

    #[ORM\Column(length: 80)]
    private string $signature = '';

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $nextRunAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $confidence = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $expenseCategory = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $revenueType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Revenue::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Revenue $expenseRevenue = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $createdAt;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private \DateTimeInterface $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;
        return $this;
    }

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    public function setFrequency(string $frequency): self
    {
        $this->frequency = $frequency;
        return $this;
    }

    public function getAmount(): float
    {
        return (float) $this->amount;
    }

    public function setAmount(float $amount): self
    {
        $this->amount = number_format($amount, 2, '.', '');
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getSignature(): string
    {
        return $this->signature;
    }

    public function setSignature(string $signature): self
    {
        $this->signature = $signature;
        return $this;
    }

    public function getNextRunAt(): ?\DateTimeInterface
    {
        return $this->nextRunAt;
    }

    public function setNextRunAt(?\DateTimeInterface $nextRunAt): self
    {
        $this->nextRunAt = $nextRunAt;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }

    public function setConfidence(?float $confidence): self
    {
        $this->confidence = $confidence;
        return $this;
    }

    public function getExpenseCategory(): ?string
    {
        return $this->expenseCategory;
    }

    public function setExpenseCategory(?string $expenseCategory): self
    {
        $this->expenseCategory = $expenseCategory;
        return $this;
    }

    public function getRevenueType(): ?string
    {
        return $this->revenueType;
    }

    public function setRevenueType(?string $revenueType): self
    {
        $this->revenueType = $revenueType;
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

    public function getExpenseRevenue(): ?Revenue
    {
        return $this->expenseRevenue;
    }

    public function setExpenseRevenue(?Revenue $expenseRevenue): self
    {
        $this->expenseRevenue = $expenseRevenue;
        return $this;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    protected function setCreatedAt(\DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function markCreatedAt(\DateTimeInterface $createdAt): self
    {
        return $this->setCreatedAt($createdAt);
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    protected function setUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function markUpdatedAt(\DateTimeInterface $updatedAt): self
    {
        return $this->setUpdatedAt($updatedAt);
    }

    public function touch(): self
    {
        $this->updatedAt = new \DateTimeImmutable();
        return $this;
    }
}

