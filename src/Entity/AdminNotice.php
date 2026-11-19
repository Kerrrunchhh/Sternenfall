<?php

namespace App\Entity;

use App\Repository\AdminNoticeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Something the admins want to hear about (a sign-up, a booked extended account, a PayPal problem); mailed in a digest (App\Game\AdminNotices). */
#[ORM\Entity(repositoryClass: AdminNoticeRepository::class)]
#[ORM\Index(name: 'idx_admin_notice_sent', columns: ['sent_at'])]
class AdminNotice
{
    public const string SIGNUP = 'signup';
    public const string EXTENDED = 'extended';
    /** A booking that went wrong: closed PayPal window, PayPal error, SDK not loaded, confirmation refused. */
    public const string PAYPAL = 'paypal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12)]
    private string $kind;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    /** Kept for when the account is gone before the mail goes out. */
    #[ORM\Column(length: 64)]
    private string $username;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** What happened, e.g. PayPal's error message and the browser. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $detail;

    public function __construct(string $kind, User $user, \DateTimeImmutable $createdAt, ?string $detail = null)
    {
        $this->kind = $kind;
        $this->detail = null === $detail ? null : mb_substr($detail, 0, 1000);
        $this->user = $user;
        $this->username = mb_substr($user->getUsername(), 0, 64);
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getKind(): string { return $this->kind; }
    public function getUser(): ?User { return $this->user; }
    public function getUsername(): string { return $this->username; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getDetail(): ?string { return $this->detail; }

    public function markSent(\DateTimeImmutable $at): void
    {
        $this->sentAt = $at;
    }
}
