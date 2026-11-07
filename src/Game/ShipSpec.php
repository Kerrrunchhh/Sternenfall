<?php

namespace App\Game;

/**
 * Static data of one ship type.
 */
final readonly class ShipSpec
{
    /**
     * @param list<Requirement> $requirements
     */
    public function __construct(
        public Resources $cost,
        /** Inhabitants needed to man one ship. */
        public int $crew,
        public int $attack,
        public int $shield,
        public int $hull,
        /** Base speed before drive research. */
        public int $speed,
        public int $cargo,
        /** Hydrogen burned per flight (before distance scaling). */
        public int $fuel,
        public ResearchType $drive,
        public array $requirements,
    ) {
    }
}
