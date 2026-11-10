<?php

namespace App\Entity;

use App\Repository\ContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A quick contact between two players. It starts as a request and counts for both
 * once the addressee accepted it.
 */
#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_contact_pair', columns: ['requester_id', 'addressee_id'])]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $requester;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $addressee;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acceptedAt = null;

    public function __construct(User $requester, User $addressee, \DateTimeImmutable $createdAt)
    {
        $this->requester = $requester;
        $this->addressee = $addressee;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequester(): User
    {
        return $this->requester;
    }

    public function getAddressee(): User
    {
        return $this->addressee;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAccepted(): bool
    {
        return null !== $this->acceptedAt;
    }

    public function accept(\DateTimeImmutable $now): void
    {
        $this->acceptedAt = $now;
    }

    public function involves(User $user): bool
    {
        return $this->requester->isSameAs($user) || $this->addressee->isSameAs($user);
    }

    /** The other player of the contact, seen from one of the two. */
    public function other(User $user): User
    {
        return $this->requester->isSameAs($user) ? $this->addressee : $this->requester;
    }
}
