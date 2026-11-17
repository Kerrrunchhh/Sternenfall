<?php

namespace App\Entity;

use App\Repository\TickerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A long-range ticker: a chat channel any player can open. The owner invites players,
 * removes them, deletes posts and can hand the ticker over to a member; admins he appoints
 * moderate with him. His deputy takes over when the owner stays away for DEPUTY_AFTER_DAYS.
 */
#[ORM\Entity(repositoryClass: TickerRepository::class)]
class Ticker
{
    public const int MAX_NAME = 40;
    public const int MAX_DESCRIPTION = 1000;
    /** Days the owner may stay offline before his deputy takes the ticker over. */
    public const int DEPUTY_AFTER_DAYS = 30;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: self::MAX_NAME)]
    private string $name;

    /** What the ticker is about (shown to members and invited players). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    /** Becomes owner when the owner hasn't been online for DEPUTY_AFTER_DAYS. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $deputy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, TickerMember> */
    #[ORM\OneToMany(targetEntity: TickerMember::class, mappedBy: 'ticker', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $members;

    public function __construct(string $name, User $owner, \DateTimeImmutable $createdAt)
    {
        $this->name = mb_substr($name, 0, self::MAX_NAME);
        $this->owner = $owner;
        $this->createdAt = $createdAt;
        $this->members = new ArrayCollection();
        $member = new TickerMember($this, $owner, $createdAt);
        $member->accept($createdAt);
        $this->members->add($member);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description ?? '';
    }

    public function describe(string $description): void
    {
        $description = trim($description);
        $this->description = '' === $description ? null : mb_substr($description, 0, self::MAX_DESCRIPTION);
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner->isSameAs($user);
    }

    /** The new owner gives up admin rights and deputyship (he has more now). */
    public function handOver(User $newOwner): void
    {
        $this->owner = $newOwner;
        $this->membership($newOwner)?->setAdmin(false);
        if (null !== $this->deputy && $this->deputy->isSameAs($newOwner)) {
            $this->deputy = null;
        }
    }

    public function getDeputy(): ?User
    {
        return $this->deputy;
    }

    public function isDeputy(User $user): bool
    {
        return null !== $this->deputy && $this->deputy->isSameAs($user);
    }

    public function setDeputy(?User $deputy): void
    {
        $this->deputy = $deputy;
    }

    public function isAdmin(User $user): bool
    {
        $membership = $this->membership($user);

        return null !== $membership && $membership->isAccepted() && $membership->isAdmin();
    }

    /** Owner or admin: may invite, remove and mute members and delete posts. */
    public function canModerate(User $user): bool
    {
        return $this->isOwnedBy($user) || $this->isAdmin($user);
    }

    /** The owner deletes any post, admins those of plain members (and former members). */
    public function canDeletePost(User $moderator, TickerPost $post): bool
    {
        if ($this->isOwnedBy($moderator)) {
            return true;
        }
        $author = $post->getAuthor();

        return $this->isAdmin($moderator) && (null === $author || $author->isSameAs($moderator) || !$this->canModerate($author));
    }

    /**
     * Whether $moderator may remove or mute $member: the owner everybody but himself,
     * admins only plain members (not the owner, not other admins).
     */
    public function canModerateMember(User $moderator, TickerMember $member): bool
    {
        $target = $member->getUser();
        if ($member->getTicker() !== $this || $target->isSameAs($moderator) || $this->isOwnedBy($target)) {
            return false;
        }

        return $this->isOwnedBy($moderator) || ($this->isAdmin($moderator) && !$member->isAdmin());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, TickerMember> members and open invitations */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function membership(User $user): ?TickerMember
    {
        return $this->members->findFirst(static fn (int $k, TickerMember $m): bool => $m->getUser()->isSameAs($user));
    }

    public function isMember(User $user): bool
    {
        return true === $this->membership($user)?->isAccepted();
    }

    /** @return list<TickerMember> accepted members, owner first, then by name */
    public function acceptedMembers(): array
    {
        $members = array_values(array_filter($this->members->toArray(), static fn (TickerMember $m): bool => $m->isAccepted()));
        usort($members, fn (TickerMember $a, TickerMember $b): int => [!$this->isOwnedBy($a->getUser()), mb_strtolower($a->getUser()->getUsername())] <=> [!$this->isOwnedBy($b->getUser()), mb_strtolower($b->getUser()->getUsername())]);

        return $members;
    }

    /** @return list<TickerMember> invitations not answered yet */
    public function invitations(): array
    {
        return array_values(array_filter($this->members->toArray(), static fn (TickerMember $m): bool => !$m->isAccepted()));
    }

    public function invite(User $user, \DateTimeImmutable $now): TickerMember
    {
        $member = new TickerMember($this, $user, $now);
        $this->members->add($member);

        return $member;
    }

    public function removeMember(TickerMember $member): void
    {
        $this->members->removeElement($member);
        if ($this->isDeputy($member->getUser())) {
            $this->deputy = null;
        }
    }
}
