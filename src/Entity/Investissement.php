<?php

namespace App\Entity;

use App\Repository\InvestissementRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: InvestissementRepository::class)]
class Investissement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?float $amountInvested = null;

    #[ORM\Column]
    private ?float $buyPrice = null;

    #[ORM\Column]
    private ?float $quantity = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $createdAt = null;

    #[ORM\ManyToOne(inversedBy: 'investissements')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Crypto $crypto = null;

    #[ORM\ManyToOne(inversedBy: 'investissements')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Objectif $objectif = null;

    #[ORM\ManyToOne(inversedBy: 'investissements')]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user_id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAmountInvested(): ?float
    {
        return $this->amountInvested;
    }

    public function setAmountInvested(float $amountInvested): static
    {
        $this->amountInvested = $amountInvested;
        return $this;
    }

    public function getBuyPrice(): ?float
    {
        return $this->buyPrice;
    }

    public function setBuyPrice(float $buyPrice): static
    {
        $this->buyPrice = $buyPrice;
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

    public function getCreatedAt(): ?\DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
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
