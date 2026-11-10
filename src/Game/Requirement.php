<?php

namespace App\Game;

use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

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

    public function label(): TranslatableInterface
    {
        return t('{subject} Stufe {level}', ['{subject}' => t($this->subject->label()), '{level}' => $this->level]);
    }
}
