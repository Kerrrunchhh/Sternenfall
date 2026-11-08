<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\ShipyardOrder;
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
            foreach ($planet->getQueue($kind) as $order) {
                $seconds = $order->getRemaining() * $order->getUnitSeconds();
                $finishesAt = $order->getFinishesAt() ?? $cursor->modify('+'.($seconds - $order->getProgressSeconds()).' seconds');
                $entries[] = [
                    'id' => $order->getId(),
                    'label' => \sprintf('%s × %s', number_format($order->getRemaining(), 0, ',', '.'), $order->getShipType()->label()),
                    'detail' => 'je '.self::duration($order->getUnitSeconds()).self::rateDetail($order),
                    'seconds' => $seconds,
                    'finishesAt' => $finishesAt,
                    'active' => $order->isStarted(),
                ];
                $cursor = $finishesAt;
            }
        }

        return $entries;
    }

    /** "200 % · gebunden: Nahrung … · Wasser … · Wasserstoff …" for orders above 100 %. */
    private static function rateDetail(ShipyardOrder $order): string
    {
        if ($order->getPercent() <= 100) {
            return '';
        }
        $bound = $order->getUnitCost()->multiply($order->getRemaining());
        $parts = [];
        foreach ([ResourceType::Food, ResourceType::Water, ResourceType::Hydrogen] as $type) {
            if ($bound->get($type) > 0) {
                $parts[] = $type->label().' '.number_format($bound->get($type), 0, ',', '.');
            }
        }

        return \sprintf(' · %d %%', $order->getPercent()).([] === $parts ? '' : ' · gebunden: '.implode(', ', $parts));
    }

    private static function duration(int $seconds): string
    {
        return GameExtension::formatDuration($seconds);
    }
}
