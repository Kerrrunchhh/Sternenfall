<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_contract_applicant', columns: ['contract_id', 'applicant_id'])]
class ContractApplication
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'applications')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Contract $contract;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $applicant;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Contract $contract, User $applicant, string $message, \DateTimeImmutable $createdAt)
    {
        $this->contract = $contract;
        $this->applicant = $applicant;
        $this->message = '' === trim($message) ? null : mb_substr(trim($message), 0, 1000);
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getContract(): Contract { return $this->contract; }
    public function getApplicant(): User { return $this->applicant; }
    public function getMessage(): ?string { return $this->message; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
