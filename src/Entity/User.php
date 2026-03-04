<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[UniqueEntity(fields: ['email'], message: 'This email is already used.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(min: 2, max: 100, minMessage: 'Full name must contain at least 2 characters.')]
    private ?string $nom = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please enter a valid email address.')]
    #[Assert\Length(max: 180, maxMessage: 'Email cannot exceed 180 characters.')]
    private string $email;

    #[ORM\Column]
    #[Assert\NotBlank(message: 'Password is required.')]
    #[Assert\Length(min: 8, max: 255, minMessage: 'Password must be at least 8 characters.')]
    #[Ignore]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'date', nullable: true)]
    #[Assert\LessThanOrEqual('today', message: 'Registration date cannot be in the future.')]
    private ?\DateTime $dateInscription = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, options: ['default' => '0.00'])]
    #[Assert\NotNull(message: 'Initial balance is required.')]
    #[Assert\PositiveOrZero(message: 'Initial balance must be a non-negative number.')]
    private string $soldeTotal = '0.00';

    #[ORM\OneToMany(
        mappedBy: 'user',
        targetEntity: Transaction::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $transactions;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Revenue::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $revenues;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Quiz::class)]
    private Collection $quizzes;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Reclamation::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $reclamations;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Image path cannot exceed 255 characters.')]
    private ?string $image = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $faceIdCredentialId = null;

    #[ORM\Column]
    private bool $faceIdEnabled = false;

    #[ORM\Column(length: 255, nullable: true)]
    #[Ignore]
    private ?string $facePlusToken = null;

    #[ORM\Column]
    private bool $facePlusEnabled = false;

    #[ORM\Column(options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(length: 64, nullable: true)]
    #[Ignore]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $isBlocked = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $blockedReason = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $blockedAt = null;

    #[ORM\Column(length: 8, nullable: true)]
    private ?string $geoCountryCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $geoCountryName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $geoRegionName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $geoCityName = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $geoDetectedIp = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $geoVpnSuspected = false;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $geoLastCheckedAt = null;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->revenues = new ArrayCollection();
        $this->quizzes = new ArrayCollection();
        $this->reclamations = new ArrayCollection();
        $this->dateInscription = new \DateTime('today');
    }

    /* ================= SECURITY ================= */

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(#[\SensitiveParameter] string $password): self
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void {}

    /* ================= BASIC GETTERS ================= */

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): self
    {
        $this->nom = $nom;
        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = strtolower($email);
        return $this;
    }

    public function getSoldeTotal(): float
    {
        return (float) $this->soldeTotal;
    }

    public function setSoldeTotal(float $solde): self
    {
        $this->soldeTotal = number_format($solde, 2, '.', '');
        return $this;
    }

    public function getDateInscription(): ?\DateTime
    {
        return $this->dateInscription;
    }

    public function setDateInscription(?\DateTime $dateInscription): self
    {
        $this->dateInscription = $dateInscription;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;
        return $this;
    }

    public function getFaceIdCredentialId(): ?string
    {
        return $this->faceIdCredentialId;
    }

    public function setFaceIdCredentialId(?string $faceIdCredentialId): self
    {
        $this->faceIdCredentialId = $faceIdCredentialId;
        return $this;
    }

    public function isFaceIdEnabled(): bool
    {
        return $this->faceIdEnabled;
    }

    public function setFaceIdEnabled(bool $faceIdEnabled): self
    {
        $this->faceIdEnabled = $faceIdEnabled;
        return $this;
    }

    public function getFacePlusToken(): ?string
    {
        return $this->facePlusToken;
    }

    public function setFacePlusToken(#[\SensitiveParameter] ?string $facePlusToken): self
    {
        $this->facePlusToken = $facePlusToken;
        return $this;
    }

    public function isFacePlusEnabled(): bool
    {
        return $this->facePlusEnabled;
    }

    public function setFacePlusEnabled(bool $facePlusEnabled): self
    {
        $this->facePlusEnabled = $facePlusEnabled;
        return $this;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): self
    {
        $this->emailVerified = $emailVerified;
        return $this;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function setEmailVerificationToken(#[\SensitiveParameter] ?string $emailVerificationToken): self
    {
        $this->emailVerificationToken = $emailVerificationToken;
        return $this;
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    protected function setEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): self
    {
        $this->emailVerifiedAt = $emailVerifiedAt;
        return $this;
    }

    public function markEmailVerifiedAt(?\DateTimeImmutable $emailVerifiedAt): self
    {
        return $this->setEmailVerifiedAt($emailVerifiedAt);
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function setIsBlocked(bool $isBlocked): self
    {
        $this->isBlocked = $isBlocked;
        return $this;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function setBlockedReason(?string $blockedReason): self
    {
        $this->blockedReason = $blockedReason;
        return $this;
    }

    public function getBlockedAt(): ?\DateTimeImmutable
    {
        return $this->blockedAt;
    }

    protected function setBlockedAt(?\DateTimeImmutable $blockedAt): self
    {
        $this->blockedAt = $blockedAt;
        return $this;
    }

    public function markBlockedAt(?\DateTimeImmutable $blockedAt): self
    {
        return $this->setBlockedAt($blockedAt);
    }

    public function getGeoCountryCode(): ?string
    {
        return $this->geoCountryCode;
    }

    public function setGeoCountryCode(?string $geoCountryCode): self
    {
        $this->geoCountryCode = $geoCountryCode;
        return $this;
    }

    public function getGeoCountryName(): ?string
    {
        return $this->geoCountryName;
    }

    public function setGeoCountryName(?string $geoCountryName): self
    {
        $this->geoCountryName = $geoCountryName;
        return $this;
    }

    public function getGeoRegionName(): ?string
    {
        return $this->geoRegionName;
    }

    public function setGeoRegionName(?string $geoRegionName): self
    {
        $this->geoRegionName = $geoRegionName;
        return $this;
    }

    public function getGeoCityName(): ?string
    {
        return $this->geoCityName;
    }

    public function setGeoCityName(?string $geoCityName): self
    {
        $this->geoCityName = $geoCityName;
        return $this;
    }

    public function getGeoDetectedIp(): ?string
    {
        return $this->geoDetectedIp;
    }

    public function setGeoDetectedIp(?string $geoDetectedIp): self
    {
        $this->geoDetectedIp = $geoDetectedIp;
        return $this;
    }

    public function isGeoVpnSuspected(): bool
    {
        return $this->geoVpnSuspected;
    }

    public function setGeoVpnSuspected(bool $geoVpnSuspected): self
    {
        $this->geoVpnSuspected = $geoVpnSuspected;
        return $this;
    }

    public function getGeoLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->geoLastCheckedAt;
    }

    protected function setGeoLastCheckedAt(?\DateTimeImmutable $geoLastCheckedAt): self
    {
        $this->geoLastCheckedAt = $geoLastCheckedAt;
        return $this;
    }

    public function markGeoLastCheckedAt(?\DateTimeImmutable $geoLastCheckedAt): self
    {
        return $this->setGeoLastCheckedAt($geoLastCheckedAt);
    }

    /* ================= TRANSACTIONS ================= */

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): self
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setUser($this);
        }

        return $this;
    }

    public function removeTransaction(Transaction $transaction): self
    {
        if ($this->transactions->removeElement($transaction)) {
            if ($transaction->getUser() === $this) {
                $transaction->setUser(null);
            }
        }

        return $this;
    }

    /* ================= REVENUES ================= */

    /**
     * @return Collection<int, Revenue>
     */
    public function getRevenues(): Collection
    {
        return $this->revenues;
    }

    public function addRevenue(Revenue $revenue): self
    {
        if (!$this->revenues->contains($revenue)) {
            $this->revenues->add($revenue);
            $revenue->setUser($this);
        }

        return $this;
    }

    public function removeRevenue(Revenue $revenue): self
    {
        $this->revenues->removeElement($revenue);

        return $this;
    }

    /* ================= BUSINESS ================= */

    public function recalculateSolde(): void
    {
        $total = 0;

        foreach ($this->transactions as $transaction) {
            if ($transaction->getType() === 'SAVING') {
                $total += $transaction->getMontant();
            } elseif ($transaction->getType() === 'EXPENSE') {
                $total -= $transaction->getMontant();
            }
        }

        $this->setSoldeTotal($total);
    }

    /* ================= QUIZZES ================= */

    /**
     * @return Collection<int, Quiz>
     */
    public function getQuizzes(): Collection
    {
        return $this->quizzes;
    }

    public function addQuiz(Quiz $quiz): self
    {
        if (!$this->quizzes->contains($quiz)) {
            $this->quizzes->add($quiz);
            $quiz->setUser($this);
        }

        return $this;
    }

    public function removeQuiz(Quiz $quiz): self
    {
        if ($this->quizzes->removeElement($quiz)) {
            if ($quiz->getUser() === $this) {
                $quiz->setUser(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        if ($this->nom && $this->email) {
            return $this->nom . ' (' . $this->email . ')';
        } elseif ($this->email) {
            return $this->email;
        } elseif ($this->nom) {
            return $this->nom;
        } else {
            return 'User #' . $this->id;
        }
    }

    /**
     * @return Collection<int, Reclamation>
     */
    public function getReclamations(): Collection
    {
        return $this->reclamations;
    }
}
