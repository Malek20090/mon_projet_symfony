<?php

namespace App\Entity;

use App\Repository\ImprevusRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImprevusRepository::class)]
class Imprevus
{
    public const TYPE_POSITIF = 'POSITIF';
    public const TYPE_NEGATIF = 'NEGATIF';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $titre = null;

    #[ORM\Column(length: 10)]
    private ?string $type = null;

    #[ORM\Column]
    private ?float $budget = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $messageEducatif = null;

    /** @var Collection<int, CasRelles> */
    #[ORM\OneToMany(targetEntity: CasRelles::class, mappedBy: 'imprevus')]
    private Collection $casRelles;

    public function __construct()
    {
        $this->casRelles = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getBudget(): ?float
    {
        return $this->budget;
    }

    public function setBudget(?float $budget): static
    {
        $this->budget = $budget;
        return $this;
    }

    public function getMessageEducatif(): ?string
    {
        return $this->messageEducatif;
    }

    public function setMessageEducatif(?string $messageEducatif): static
    {
        $this->messageEducatif = $messageEducatif;
        return $this;
    }

    /** @return Collection<int, CasRelles> */
    public function getCasRelles(): Collection
    {
        return $this->casRelles;
    }

    public function addCasRelle(CasRelles $casRelle): static
    {
        if (!$this->casRelles->contains($casRelle)) {
            $this->casRelles->add($casRelle);
            $casRelle->setImprevus($this);
        }
        return $this;
    }

    public function removeCasRelle(CasRelles $casRelle): static
    {
        if ($this->casRelles->removeElement($casRelle)) {
            if ($casRelle->getImprevus() === $this) {
                $casRelle->setImprevus(null);
            }
        }
        return $this;
    }
}
