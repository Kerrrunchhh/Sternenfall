<?php

namespace App\Twig;

use Psr\Clock\ClockInterface;
use Twig\Attribute\AsTwigFunction;

/** Server time for the live game clock, so players time fleets by the server, not their own PC clock. */
final readonly class ClockExtension
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /** Current server time in milliseconds since the Unix epoch. */
    #[AsTwigFunction('server_time_ms')]
    public function serverTimeMs(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }
}
