<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A trace of a deleted account, for the statistics (sign-offs in the last 24 hours). */
#[ORM\Entity]
#[ORM\Index(name: 'idx_deleted_account_at', columns: ['deleted_at'])]
class DeletedAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $username;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $deletedAt;

    public function __construct(string $username, \DateTimeImmutable $deletedAt)
    {
        $this->username = $username;
        $this->deletedAt = $deletedAt;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
