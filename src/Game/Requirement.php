<?php

namespace App\Game;

/**
 * "Building or research X must be at least at level N."
 */
final readonly class Requirement
{
    public function __construct(
        public BuildingType|ResearchType $subject,
        public int $level,
    ) {
    }

    public function label(): string
    {
        return \sprintf('%s Stufe %d', $this->subject->label(), $this->level);
    }
}
