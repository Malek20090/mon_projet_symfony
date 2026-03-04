<?php

namespace App\Entity;

use App\Repository\UserBehaviorProfileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserBehaviorProfileRepository::class)]
#[ORM\Table(name: 'user_behavior_profile')]
class UserBehaviorProfile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE', unique: true)]
    private ?User $user = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $score = 0;

    #[ORM\Column(type: Types::STRING, length: 60)]
    private string $profileType = 'Insufficient Data';

    /** @var array<int, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $strengths = [];

    /** @var array<int, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $weaknesses = [];

    /** @var array<int, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $nextActions = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function setScore(int $score): self
    {
        $this->score = max(0, min(100, $score));

        return $this;
    }

    public function getProfileType(): string
    {
        return $this->profileType;
    }

    public function setProfileType(string $profileType): self
    {
        $this->profileType = $profileType;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getStrengths(): array
    {
        return $this->strengths;
    }

    /**
     * @param array<int, string> $strengths
     */
    public function setStrengths(array $strengths): self
    {
        $this->strengths = array_values($strengths);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getWeaknesses(): array
    {
        return $this->weaknesses;
    }

    /**
     * @param array<int, string> $weaknesses
     */
    public function setWeaknesses(array $weaknesses): self
    {
        $this->weaknesses = array_values($weaknesses);

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getNextActions(): array
    {
        return $this->nextActions;
    }

    /**
     * @param array<int, string> $nextActions
     */
    public function setNextActions(array $nextActions): self
    {
        $this->nextActions = array_values($nextActions);

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    protected function setUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function markUpdatedAt(\DateTimeImmutable $updatedAt): self
    {
        return $this->setUpdatedAt($updatedAt);
    }
}
