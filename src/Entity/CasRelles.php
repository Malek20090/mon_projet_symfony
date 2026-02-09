<?php

namespace App\Entity;

use App\Repository\CasRellesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CasRellesRepository::class)]
class CasRelles
{
    public const TYPE_POSITIF = 'POSITIF';
    public const TYPE_NEGATIF = 'NEGATIF';

    public const SOLUTION_FONDS_SECURITE = 'FONDS_SECURITE';
    public const SOLUTION_EPARGNE = 'EPARGNE';
    public const SOLUTION_FAMILLE = 'FAMILLE';
    public const SOLUTION_COMPTE = 'COMPTE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'casRelles')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Imprevus $imprevus = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?SavingAccount $epargne = null;

    #[ORM\Column(length: 150)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 10)]
    private ?string $type = null;

    #[ORM\Column]
    private ?float $montant = null;

    #[ORM\Column(length: 30)]
    private ?string $solution = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTimeInterface $dateEffet = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $resultat = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $raisonRefus = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getImprevus(): ?Imprevus
    {
        return $this->imprevus;
    }

    public function setImprevus(?Imprevus $imprevus): static
    {
        $this->imprevus = $imprevus;
        return $this;
    }

    public function getEpargne(): ?SavingAccount
    {
        return $this->epargne;
    }

    public function setEpargne(?SavingAccount $epargne): static
    {
        $this->epargne = $epargne;
        return $this;
    }

    public function getTitre(): ?string
    {
        return $this->titre;
    }

    public function setTitre(?string $titre): static
    {
        $this->titre = $titre;
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getMontant(): ?float
    {
        return $this->montant;
    }

    public function setMontant(?float $montant): static
    {
        $this->montant = $montant;
        return $this;
    }

    public function getSolution(): ?string
    {
        return $this->solution;
    }

    public function setSolution(?string $solution): static
    {
        $this->solution = $solution;
        return $this;
    }

    public function getDateEffet(): ?\DateTimeInterface
    {
        return $this->dateEffet;
    }

    public function setDateEffet(?\DateTimeInterface $dateEffet): static
    {
        $this->dateEffet = $dateEffet;
        return $this;
    }

    public function getResultat(): ?string
    {
        return $this->resultat;
    }

    public function setResultat(?string $resultat): static
    {
        $this->resultat = $resultat;
        return $this;
    }

    public function getRaisonRefus(): ?string
    {
        return $this->raisonRefus;
    }

    public function setRaisonRefus(?string $raisonRefus): static
    {
        $this->raisonRefus = $raisonRefus;
        return $this;
    }
}
