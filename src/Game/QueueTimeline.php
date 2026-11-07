<?php

namespace App\Game;

use App\Entity\Planet;
use App\Twig\GameExtension;
use Psr\Clock\ClockInterface;

/**
 * Projects a planet's queues onto a timeline for display: duration of every
 * entry and when it is expected to be finished.
 */
final readonly class QueueTimeline
{
    public function __construct(
        private Economy $economy,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<array{id: int, label: string, detail: string, seconds: int, finishesAt: \DateTimeImmutable, active: bool}> */
    public function entries(Planet $planet, QueueKind $kind): array
    {
        $cursor = \DateTimeImmutable::createFromInterface($this->clock->now());
        $entries = [];

        if (QueueKind::Buildings === $kind) {
            foreach ($planet->getBuildingQueue() as $order) {
                $seconds = $order->getSeconds() ?? $this->economy->buildSeconds($order->getBuildingType(), $order->getTargetLevel(), $planet);
                $finishesAt = $order->getFinishesAt() ?? $cursor->modify('+'.($seconds - $order->getProgressSeconds()).' seconds');
                $entries[] = [
                    'id' => $order->getId(),
                    'label' => \sprintf('%s → Stufe %d', $order->getBuildingType()->label(), $order->getTargetLevel()),
                    'detail' => '',
                    'seconds' => $seconds,
                    'finishesAt' => $finishesAt,
                    'active' => $order->isStarted(),
                ];
                $cursor = $finishesAt;
            }
        } else {
            foreach ($planet->getShipyardQueue() as $order) {
                $seconds = $order->getRemaining() * $order->getUnitSeconds();
                $finishesAt = $order->getFinishesAt() ?? $cursor->modify('+'.($seconds - $order->getProgressSeconds()).' seconds');
                $entries[] = [
                    'id' => $order->getId(),
                    'label' => \sprintf('%s × %s', number_format($order->getRemaining(), 0, ',', '.'), $order->getShipType()->label()),
                    'detail' => 'je '.self::duration($order->getUnitSeconds()),
                    'seconds' => $seconds,
                    'finishesAt' => $finishesAt,
                    'active' => $order->isStarted(),
                ];
                $cursor = $finishesAt;
            }
        }

        return $entries;
    }

    private static function duration(int $seconds): string
    {
        return GameExtension::formatDuration($seconds);
    }
}
