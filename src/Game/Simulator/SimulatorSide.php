<?php

namespace App\Game\Simulator;

/** One side of the simulator form as posted: pasted text, ship counts and research levels (as typed). */
final readonly class SimulatorSide
{
    /**
     * @param array<string, string> $ships    ship type => count as typed ("1.234" allowed)
     * @param array<string, string> $research combat research type => level
     */
    public function __construct(
        public string $report = '',
        public array $ships = [],
        public array $research = [],
    ) {
    }
}
