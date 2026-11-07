<?php

namespace App\Entity;

/**
 * Common shape of building and shipyard queue entries, used for reordering.
 */
interface QueueItem
{
    public function getId(): ?int;

    public function getPosition(): int;

    public function setPosition(int $position): void;

    public function isStarted(): bool;

    /** Pauses work and remembers the progress. */
    public function stop(\DateTimeImmutable $now): void;
}
