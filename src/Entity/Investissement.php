<?php

namespace App\Entity;

use App\Repository\InvestissementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: InvestissementRepository::class)]
class Investissement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\NotNull(message: 'Le montant est obligatoire.')]
    #[Assert\GreaterThanOrEqual(
    value: 1,
    message: 'Le montant investi doit être au minimum de 1 dollar.'
    )]
    private ?string $amountInvested = null;


    #[ORM\Column(type: Types::DECIMAL, precision: 20, scale: 8)]
    private ?string $buyPrice = null;

    #[ORM\Column]
    private ?float $quantity = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'investissements')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Crypto $crypto = null;

    #[ORM\ManyToOne(inversedBy: 'investissements')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Objectif $objectif = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private ?User $user_id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAmountInvested(): ?float
    {
        return $this->amountInvested !== null ? (float) $this->amountInvested : null;
    }

    public function setAmountInvested(float $amountInvested): static
    {
        $this->amountInvested = number_format($amountInvested, 2, '.', '');
        return $this;
    }

    public function getBuyPrice(): ?float
    {
        return $this->buyPrice !== null ? (float) $this->buyPrice : null;
    }

    public function setBuyPrice(float $buyPrice): static
    {
        $this->buyPrice = number_format($buyPrice, 8, '.', '');
        return $this;
    }

    public function getQuantity(): ?float
    {
        return $this->quantity;
    }

    public function setQuantity(float $quantity): static
    {
        $this->quantity = $quantity;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    protected function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function markCreatedAt(\DateTimeImmutable $createdAt): static
    {
        return $this->setCreatedAt($createdAt);
    }

    public function getCrypto(): ?Crypto
    {
        return $this->crypto;
    }

    public function setCrypto(?Crypto $crypto): static
    {
        $this->crypto = $crypto;
        return $this;
    }

    public function getObjectif(): ?Objectif
    {
        return $this->objectif;
    }

    public function setObjectif(?Objectif $objectif): static
    {
        $this->objectif = $objectif;
        return $this;
    }

    public function getUserId(): ?User
    {
        return $this->user_id;
    }

    public function setUserId(?User $user_id): static
    {
        $this->user_id = $user_id;
        return $this;
    }
}
