<?php

namespace App\Game\Poll;

/** A player's vote: the ticked answers (indexes) and an optional reason. */
final readonly class VoteForm
{
    /** @param list<string> $choices */
    public function __construct(
        public array $choices = [],
        public string $reason = '',
    ) {
    }
}
