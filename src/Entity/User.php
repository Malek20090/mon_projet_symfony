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

    #[ORM\Column(length: 180, unique: true, nullable: true)]
    private ?string $email = null;

    #[ORM\Column]
    private string $password;

    /**
     * Single role stored in DB (column `role`), exposed as array for UserInterface::getRoles()
     */
   #[ORM\Column(type: 'json')]
private array $roles = [];


    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTime $dateInscription = null;

    #[ORM\Column]
    private float $soldeTotal = 0;

    /**
     * Not mapped to database. If you want to persist it, add a migration
     * to create `image` column on `user` table and add an ORM\Column.
     */
    private ?string $image = null;

    #[ORM\OneToMany(
        mappedBy: 'user',
        targetEntity: Transaction::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    private Collection $transactions;

    /**
     * @var Collection<int, Revenue>
     */
    #[ORM\OneToMany(targetEntity: Revenue::class, mappedBy: 'user')]
    private Collection $revenues;

    /**
     * @var Collection<int, Quiz>
     */
    #[ORM\OneToMany(targetEntity: Quiz::class, mappedBy: 'user')]
    private Collection $quizzes;

    public function __construct()
    {
        $this->transactions = new ArrayCollection();
        $this->revenues = new ArrayCollection();
        $this->quizzes = new ArrayCollection();
        $this->dateInscription = new \DateTime();
        $this->soldeTotal = 0;
    }

    /* ================= IMAGE (NON PERSISTÉE) ================= */

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function setImage(?string $image): self
    {
        $this->image = $image;
        return $this;
    }

    /* ================= SECURITY ================= */

    public function getUserIdentifier(): string
    {
        return (string) ($this->email ?? '');
    }

   public function getRoles(): array
{
    $roles = $this->roles;
    $roles[] = 'ROLE_USER';
    return array_unique($roles);
}


   public function setRoles(array $roles): self
{
    $this->roles = $roles;
    return $this;
}

    public function getRole(): ?string
    {
        // Return the first role from the array, or null if empty
        return !empty($this->roles) ? reset($this->roles) : null;
    }

    public function setRole(?string $role): self
    {
        // Set the roles array with the single role, or empty array if null
        $this->roles = $role !== null ? [$role] : [];
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

    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
    }

    /* ================= GETTERS / SETTERS ================= */

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email ? strtolower($email) : null;
        return $this;
    }

    public function getDateInscription(): ?\DateTime
    {
        return $this->dateInscription;
    }

    public function setDateInscription(?\DateTime $date): self
    {
        $this->dateInscription = $date;
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
