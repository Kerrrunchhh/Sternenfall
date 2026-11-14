<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\ShipyardOrder;
use App\Twig\GameExtension;
use Psr\Clock\ClockInterface;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

/**
 * Projects a planet's queues onto a timeline for display: duration of every
 * entry and when it is expected to be finished.
 */
final readonly class QueueTimeline
{
    /** From this progress on, cancelling an order (or the research) asks first. */
    public const float CONFIRM_CANCEL_FROM = 0.1;

    public function __construct(
        private Economy $economy,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<array{id: int|null, label: \Symfony\Contracts\Translation\TranslatableInterface, detail: string|\Symfony\Contracts\Translation\TranslatableInterface, seconds: int, finishesAt: \DateTimeImmutable, active: bool, progress: float}> */
    public function entries(Planet $planet, QueueKind $kind): array
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $cursor = $now;
        $entries = [];

        if (QueueKind::Buildings === $kind) {
            foreach ($planet->getBuildingQueue() as $order) {
                $seconds = $order->getSeconds() ?? $this->economy->buildSeconds($order->getBuildingType(), $order->getTargetLevel(), $planet, $cursor);
                $finishesAt = $order->getFinishesAt() ?? $cursor->modify('+'.($seconds - $order->getProgressSeconds()).' seconds');
                $entries[] = [
                    'id' => $order->getId(),
                    'label' => t('{building} → Stufe {level}', ['{building}' => t($order->getBuildingType()->label()), '{level}' => $order->getTargetLevel()]),
                    'detail' => '',
                    'seconds' => $seconds,
                    'finishesAt' => $finishesAt,
                    'active' => $order->isStarted(),
                    'progress' => $order->progress($now),
                ];
                $cursor = $finishesAt;
            }
        } else {
            foreach ($planet->getQueue($kind) as $order) {
                $seconds = $order->getRemaining() * $order->getUnitSeconds();
                $finishesAt = $order->getFinishesAt() ?? $cursor->modify('+'.($seconds - $order->getProgressSeconds()).' seconds');
                $entries[] = [
                    'id' => $order->getId(),
                    'label' => t('{count} × {ship}', ['{count}' => new Num($order->getRemaining()), '{ship}' => t($order->getShipType()->label())]),
                    'detail' => t('je {duration}{rate}', ['{duration}' => self::duration($order->getUnitSeconds()), '{rate}' => self::rateDetail($order)]),
                    'seconds' => $seconds,
                    'finishesAt' => $finishesAt,
                    'active' => $order->isStarted(),
                    'progress' => $order->progress($now),
                ];
                $cursor = $finishesAt;
            }
        }

        return $entries;
    }

    /** "200 % · gebunden: Nahrung … · Wasser … · Wasserstoff …" for orders above 100 %. */
    private static function rateDetail(ShipyardOrder $order): string|\Symfony\Contracts\Translation\TranslatableInterface
    {
        if ($order->getPercent() <= 100) {
            return '';
        }
        $bound = $order->getUnitCost()->multiply($order->getRemaining());
        $goods = [];
        foreach ([ResourceType::Food, ResourceType::Water, ResourceType::Hydrogen] as $type) {
            if ($bound->get($type) > 0) {
                $goods[$type->value] = $bound->get($type);
            }
        }

        return [] === $goods
            ? t(' · {percent} %', ['{percent}' => $order->getPercent()])
            : t(' · {percent} % · gebunden: {goods}', ['{percent}' => $order->getPercent(), '{goods}' => \App\I18n\Goods::summary($goods)]);
    }

    private static function duration(int $seconds): string
    {
        return GameExtension::formatDuration($seconds);
    }
}
