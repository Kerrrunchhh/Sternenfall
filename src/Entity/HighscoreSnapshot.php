<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Once an hour: every ranked player's values, so the highscores can show how places changed in 24 hours. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_highscore_snapshot_taken', columns: ['taken_at'])]
class HighscoreSnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param array<int, array{name: string, lifeForm: string, since: int, points: int, research: int, experience: float, credits: float, planets: int}> $players user id => values
     */
    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $takenAt,
        #[ORM\Column(type: Types::JSON)]
        private array $players,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTakenAt(): \DateTimeImmutable
    {
        return $this->takenAt;
    }

    /** @return array<int, array{name: string, lifeForm: string, since: int, points: int, research: int, experience: float, credits: float, planets: int}> */
    public function getPlayers(): array
    {
        return $this->players;
    }
}
