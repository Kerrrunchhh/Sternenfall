<?php

namespace App\Game\Simulator;

/** What ForceParser found in a pasted text. */
final readonly class ParsedForce
{
    /**
     * @param array<string, int> $ships    ship type => count
     * @param array<string, int> $research combat research type => level (empty if the text names none)
     */
    public function __construct(
        public array $ships,
        public array $research,
        public ?string $owner,
    ) {
    }
}
