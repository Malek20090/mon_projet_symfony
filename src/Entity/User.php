<?php

namespace App\Entity;

use App\Repository\UserRepository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $nom = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private string $password = '';

    /**
     * DB column is: role (STRING), not roles (JSON)
     */
    #[ORM\Column(name: 'role', length: 50, nullable: true)]
    private ?string $role = 'ROLE_USER';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateInscription = null;

    #[ORM\Column(type: Types::FLOAT)]
    private float $soldeTotal = 0.0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    // ✅ RELATIONS

    #[ORM\OneToMany(
        mappedBy: 'user',
        targetEntity: Transaction::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $transactions;

    #[ORM\OneToMany(targetEntity: Revenue::class, mappedBy: 'user')]
    private Collection $revenues;

    #[ORM\OneToMany(targetEntity: Quiz::class, mappedBy: 'user')]
    private Collection $quizzes;

    // ✅ FIX FOR YOUR ERROR:
    // Investissement has: #[ORM\ManyToOne(inversedBy: 'investissements')] private ?User $user_id = null;
    // so User MUST have this inverse field:

    #[ORM\OneToMany(mappedBy: 'user_id', targetEntity: Investissement::class)]
    private Collection $investissements;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->revenues = new ArrayCollection();
        $this->quizzes = new ArrayCollection();
        $this->investissements = new ArrayCollection();

        $this->dateInscription = new \DateTime();
        $this->soldeTotal = 0.0;
        $this->role = $this->role ?: 'ROLE_USER';
    }

    // ================= SECURITY =================

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /** @deprecated since Symfony 5.3, use getUserIdentifier() */
    public function getUsername(): string
    {
        return $this->getUserIdentifier();
    }

    public function getRoles(): array
    {
        $r = $this->role ?: 'ROLE_USER';
        return array_values(array_unique([$r, 'ROLE_USER']));
    }

    public function setRoles(array $roles): static
    {
        // On garde le premier rôle, car DB = string
        $this->role = $roles[0] ?? 'ROLE_USER';
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;
        return $this;
    }

    public function eraseCredentials(): void
    {
        // nothing
    }

    // ================= GETTERS / SETTERS =================

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower($email);
        return $this;
    }

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): static
    {
        $this->role = $role ?: 'ROLE_USER';
        return $this;
    }

    public function getDateInscription(): ?\DateTimeInterface
    {
        return $this->dateInscription;
    }

    public function setDateInscription(?\DateTimeInterface $dateInscription): static
    {
        $this->dateInscription = $dateInscription;
        return $this;
    }

    public function getSoldeTotal(): float
    {
        return $this->soldeTotal;
    }

    public function setSoldeTotal(float $soldeTotal): static
    {
        $this->soldeTotal = $soldeTotal;
        return $this;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): static
    {
        $this->image = $image;
        return $this;
    }

    // ================= TRANSACTIONS =================

    /**
     * @return Collection<int, Transaction>
     */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    public function addTransaction(Transaction $transaction): static
    {
        if (!$this->transactions->contains($transaction)) {
            $this->transactions->add($transaction);
            $transaction->setUser($this);
        }
        return $this;
    }

    public function removeTransaction(Transaction $transaction): static
    {
        if ($this->transactions->removeElement($transaction)) {
            if ($transaction->getUser() === $this) {
                $transaction->setUser(null);
            }
        }
        return $this;
    }

    // ================= REVENUES =================

    /**
     * @return Collection<int, Revenue>
     */
    public function getRevenues(): Collection
    {
        return $this->revenues;
    }

    public function addRevenue(Revenue $revenue): static
    {
        if (!$this->revenues->contains($revenue)) {
            $this->revenues->add($revenue);
            $revenue->setUser($this);
        }
        return $this;
    }

    public function removeRevenue(Revenue $revenue): static
    {
        if ($this->revenues->removeElement($revenue)) {
            if ($revenue->getUser() === $this) {
                $revenue->setUser(null);
            }
        }
        return $this;
    }

    // ================= QUIZZES =================

    /**
     * @return Collection<int, Quiz>
     */
    public function getQuizzes(): Collection
    {
        return $this->quizzes;
    }

    public function addQuiz(Quiz $quiz): static
    {
        if (!$this->quizzes->contains($quiz)) {
            $this->quizzes->add($quiz);
            $quiz->setUser($this);
        }
        return $this;
    }

    public function removeQuiz(Quiz $quiz): static
    {
        if ($this->quizzes->removeElement($quiz)) {
            if ($quiz->getUser() === $this) {
                $quiz->setUser(null);
            }
        }
        return $this;
    }

    // ================= INVESTISSEMENTS (FIX) =================

    /**
     * @return Collection<int, Investissement>
     */
    public function getInvestissements(): Collection
    {
        return $this->investissements;
    }

    public function addInvestissement(Investissement $investissement): static
    {
        if (!$this->investissements->contains($investissement)) {
            $this->investissements->add($investissement);
            $investissement->setUserId($this);
        }
        return $this;
    }

    public function removeInvestissement(Investissement $investissement): static
    {
        if ($this->investissements->removeElement($investissement)) {
            if ($investissement->getUserId() === $this) {
                $investissement->setUserId(null);
            }
        }
        return $this;
    }

    // ================= BUSINESS =================

    public function recalculateSolde(): void
    {
        $total = 0.0;

        foreach ($this->transactions as $transaction) {
            if ($transaction->getType() === 'SAVING') {
                $total += $transaction->getMontant();
            } elseif ($transaction->getType() === 'EXPENSE') {
                $total -= $transaction->getMontant();
            }
        }

        $this->soldeTotal = $total;
    }
}
