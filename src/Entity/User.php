<?php

namespace App\Entity;

use App\Repository\UserRepository;
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

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $nom = null;

    // ✅ email unique (ok)
    #[ORM\Column(length: 150, unique: true)]
    private ?string $email = null;

    // ✅ password not null (ok)
    #[ORM\Column(length: 255)]
    private string $password = '';

    /**
     * IMPORTANT:
     * Your DB has column: role (STRING), NOT roles (JSON).
     * So we map role here, and we return it as array in getRoles().
     */
    #[ORM\Column(name: 'role', length: 50, nullable: true)]
    private ?string $role = 'ROLE_USER';

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $dateInscription = null;

    #[ORM\Column(nullable: true)]
    private ?float $soldeTotal = null;

    // ----------------------------
    // Required by Symfony Security
    // ----------------------------

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /** @deprecated since Symfony 5.3, use getUserIdentifier() */
    public function getUsername(): string
    {
        return $this->getUserIdentifier();
    }

    /**
     * Symfony expects roles as array.
     * We store only ONE role in DB (string), so we wrap it.
     */
    public function getRoles(): array
    {
        $r = $this->role ?: 'ROLE_USER';
        return array_values(array_unique([$r, 'ROLE_USER']));
    }

    /**
     * Optional: if someone calls setRoles(), we keep the first one in "role".
     * This avoids breaking security tools.
     */
    public function setRoles(array $roles): static
    {
        $this->role = $roles[0] ?? 'ROLE_USER';
        return $this;
    }

    public function eraseCredentials(): void
    {
        // Clear temporary sensitive data if needed
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

    // ----------------------------
    // Your existing getters/setters
    // ----------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNom(): ?string
    {
        return $this->nom;
    }

    public function setNom(?string $nom): static
    {
        $this->nom = $nom;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    // ✅ DB column is "role"
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

    public function getSoldeTotal(): ?float
    {
        return $this->soldeTotal;
    }

    public function setSoldeTotal(?float $soldeTotal): static
    {
        $this->soldeTotal = $soldeTotal;
        return $this;
    }
}
