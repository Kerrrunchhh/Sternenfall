<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One login: IP address, browser and – only with the player's consent – a device fingerprint.
 * Used to spot multi accounts; kept for 30 days (see MultiAccounts::KEEP_DAYS).
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_login_record_ip', columns: ['ip'])]
#[ORM\Index(name: 'idx_login_record_fingerprint', columns: ['fingerprint'])]
#[ORM\Index(name: 'idx_login_record_at', columns: ['created_at'])]
class LoginRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 45)]
    private string $ip;

    #[ORM\Column(length: 255)]
    private string $userAgent;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fingerprint;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $ip, string $userAgent, ?string $fingerprint, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->ip = mb_substr($ip, 0, 45);
        $this->userAgent = mb_substr($userAgent, 0, 255);
        $this->fingerprint = $fingerprint;
        $this->createdAt = $createdAt;
    }
}
