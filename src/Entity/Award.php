<?php

namespace App\Entity;

use App\Game\AwardPeriod;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** A bronze, silver or golden arrow: the most combat experience of a week, month or year. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'award_period', columns: ['period', 'period_start'])]
class Award
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(enumType: AwardPeriod::class)]
        private AwardPeriod $period,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $periodStart,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Column]
        private float $experience,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $awardedAt,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriod(): AwardPeriod
    {
        return $this->period;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getExperience(): float
    {
        return $this->experience;
    }

    public function getAwardedAt(): \DateTimeImmutable
    {
        return $this->awardedAt;
    }

    /** "KW 46/2026", "11/2026", "2026". */
    public function getPeriodName(): \Symfony\Contracts\Translation\TranslatableInterface
    {
        return $this->period->name($this->periodStart);
    }
}
