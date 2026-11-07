<?php

namespace App\Game;

/**
 * Deposing an absent founder: more than half of all commanders (the rank
 * directly below the founder) must back the same successor.
 */
final class FounderElection
{
    /** Votes a candidate needs out of $eligibleVoters. */
    public static function votesNeeded(int $eligibleVoters): int
    {
        return intdiv($eligibleVoters, 2) + 1;
    }

    /**
     * @param array<int, int> $votesByCandidate candidate id => number of votes
     *
     * @return int|null id of the elected successor, if any
     */
    public static function winner(array $votesByCandidate, int $eligibleVoters): ?int
    {
        if ($eligibleVoters < 1) {
            return null;
        }
        foreach ($votesByCandidate as $candidate => $votes) {
            if ($votes >= self::votesNeeded($eligibleVoters)) {
                return $candidate;
            }
        }

        return null;
    }
}
