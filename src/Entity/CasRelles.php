<?php

namespace App\Entity;

use App\Repository\CasRellesRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Entity\FinancialGoal;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

#[ORM\Entity(repositoryClass: CasRellesRepository::class)]
#[Vich\Uploadable]
class CasRelles
{
    public const TYPE_POSITIF = 'POSITIF';
    public const TYPE_NEGATIF = 'NEGATIF';

    public const SOLUTION_FONDS_SECURITE = 'FONDS_SECURITE';
    public const SOLUTION_FAMILLE = 'FAMILLE';
public const SOLUTION_OBJECTIF = 'OBJECTIF';
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'casRelles')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Imprevus $imprevus = null;

    
    #[ORM\Column(length: 150)]
    private ?string $titre = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 10)]
    private ?string $type = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $categorie = null;

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

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?User $confirmedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $justificatifFileName = null;

    #[Vich\UploadableField(mapping: 'cas_reel_justificatif', fileNameProperty: 'justificatifFileName')]
    private ?File $justificatifFile = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;
#[ORM\ManyToOne]
#[ORM\JoinColumn(nullable: true)]
private ?FinancialGoal $financialGoal = null;
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
public function getFinancialGoal(): ?FinancialGoal
{
    return $this->financialGoal;
}

public function setFinancialGoal(?FinancialGoal $financialGoal): static
{
    $this->financialGoal = $financialGoal;
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

    public function getCategorie(): ?string
    {
        return $this->categorie;
    }

    public function setCategorie(?string $categorie): static
    {
        $this->categorie = $categorie;
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

    public function getConfirmedBy(): ?User
    {
        return $this->confirmedBy;
    }

    public function setConfirmedBy(?User $confirmedBy): static
    {
        $this->confirmedBy = $confirmedBy;
        return $this;
    }

    public function getConfirmedAt(): ?\DateTimeImmutable
    {
        return $this->confirmedAt;
    }

    public function setConfirmedAt(?\DateTimeImmutable $confirmedAt): static
    {
        $this->confirmedAt = $confirmedAt;
        return $this;
    }

    public function getJustificatifFileName(): ?string
    {
        return $this->justificatifFileName;
    }

    public function setJustificatifFileName(?string $justificatifFileName): static
    {
        $this->justificatifFileName = $justificatifFileName;
        return $this;
    }

    public function getJustificatifFile(): ?File
    {
        return $this->justificatifFile;
    }

    public function setJustificatifFile(?File $justificatifFile): static
    {
        $this->justificatifFile = $justificatifFile;
        if ($justificatifFile !== null) {
            $this->updatedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
