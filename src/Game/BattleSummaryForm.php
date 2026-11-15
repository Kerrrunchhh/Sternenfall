<?php

namespace App\Game;

/** The posted battle summary form: report links pasted by the player. */
final readonly class BattleSummaryForm
{
    public function __construct(
        public string $links = '',
    ) {
    }

    /** @return list<string> the report hashes in the text (links or bare hashes), each once */
    public function hashes(): array
    {
        preg_match_all('/(?<![0-9a-f])[0-9a-f]{24}(?![0-9a-f])/i', $this->links, $m);

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }
}
