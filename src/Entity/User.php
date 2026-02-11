<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nom = null;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $dateInscription = null;

    #[ORM\Column]
    private float $soldeTotal = 0;

    #[ORM\OneToMany(
        mappedBy: 'user',
        targetEntity: Transaction::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $transactions;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Revenue::class)]
    private Collection $revenues;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Quiz::class)]
    private Collection $quizzes;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->revenues = new ArrayCollection();
        $this->quizzes = new ArrayCollection();
        $this->dateInscription = new \DateTime();
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

    public function setPassword(string $password): self
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
        return $this->soldeTotal;
    }

    public function setSoldeTotal(float $solde): self
    {
        $this->soldeTotal = $solde;
        return $this;
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
        if ($this->revenues->removeElement($revenue)) {
            if ($revenue->getUser() === $this) {
                $revenue->setUser(null);
            }
        }

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

        $this->soldeTotal = $total;
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
}
