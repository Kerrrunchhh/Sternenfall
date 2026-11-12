<?php

namespace App\Entity;

use App\Repository\AttackBanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A general attack ban imposed by an admin (e.g. while the server is hard to reach). */
#[ORM\Entity(repositoryClass: AttackBanRepository::class)]
class AttackBan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $liftedAt = null;

    public function __construct(
        #[ORM\Column(length: 500)]
        private string $reason,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $startedAt,
        /** Null: until an admin lifts it. */
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $endsAt = null,
    ) {
        $this->reason = mb_substr(trim($reason), 0, 500);
    }

    public function getId(): ?int { return $this->id; }
    public function getReason(): string { return $this->reason; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function getLiftedAt(): ?\DateTimeImmutable { return $this->liftedAt; }

    public function isActive(\DateTimeImmutable $now): bool
    {
        return null === $this->liftedAt && $this->startedAt <= $now && (null === $this->endsAt || $now < $this->endsAt);
    }

    public function lift(\DateTimeImmutable $at): void
    {
        $this->liftedAt ??= $at;
    }
}
