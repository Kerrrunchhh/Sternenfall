<?php

namespace App\Game;

use App\Entity\ActionLogEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Player activity log for admins who suspect cheating (pushing, multi accounts): fleet orders,
 * deliveries to other players, trades, payments and battles, each with the player it concerned.
 * Plain German text, kept KEEP_DAYS (see the privacy policy). Admins' look-ups are logged as well.
 */
final readonly class ActionLog
{
    public const int KEEP_DAYS = 30;

    public const string FLEET = 'fleet';
    public const string DELIVERY = 'delivery';
    public const string TRADE = 'trade';
    public const string CREDITS = 'credits';
    public const string TREASURY = 'treasury';
    public const string COMBAT = 'combat';
    public const string ADMIN_VIEW = 'admin_view';

    /** Labels of the kinds for the admin page. */
    public const array KINDS = [
        self::FLEET => 'Flottenbefehl',
        self::DELIVERY => 'Lieferung',
        self::TRADE => 'Handel',
        self::CREDITS => 'Credits',
        self::TREASURY => 'Allianzkasse',
        self::COMBAT => 'Kampf',
        self::ADMIN_VIEW => 'Admin-Einsicht',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /** Adds an entry (persisted, flushed by the caller); system accounts are not logged. */
    public function record(User $actor, string $kind, string $text, ?User $other = null): void
    {
        if ($actor->isSystem()) {
            return;
        }
        $this->em->persist(new ActionLogEntry($actor, $kind, $text, null !== $other && !$other->isSameAs($actor) && !$other->isSystem() ? $other : null, $this->now()));
        // Old entries go now and then (a cheap indexed delete).
        if (1 === random_int(1, 200)) {
            $this->prune();
        }
    }

    /** Deletes entries older than KEEP_DAYS. */
    public function prune(): int
    {
        return (int) $this->em->getConnection()->executeStatement('DELETE FROM action_log_entry WHERE created_at < ?', [$this->now()->modify('-'.self::KEEP_DAYS.' days')->format('Y-m-d H:i:s')]);
    }

    /**
     * What a player did or what concerned him, newest first – with $with only what the two had to
     * do with each other.
     *
     * @return list<ActionLogEntry>
     */
    public function forPlayer(User $player, ?User $with = null, ?string $kind = null, int $limit = 1000): array
    {
        $qb = $this->em->createQueryBuilder()->select('e')->from(ActionLogEntry::class, 'e')
            ->orderBy('e.createdAt', 'DESC')->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('p', $player);
        if (null !== $with) {
            $qb->where('(e.actor = :p AND e.other = :w) OR (e.actor = :w AND e.other = :p)')->setParameter('w', $with);
        } else {
            $qb->where('e.actor = :p OR e.other = :p');
        }
        if (null !== $kind) {
            $qb->andWhere('e.kind = :kind')->setParameter('kind', $kind);
        }

        return $qb->getQuery()->getResult();
    }

    /** "Eisen 1.000, Titan 500" (nothing: "–"). */
    public static function resources(Resources $resources): string
    {
        $parts = [];
        foreach (ResourceType::cases() as $type) {
            if ($resources->get($type) >= 1) {
                $parts[] = $type->label().' '.number_format($resources->get($type), 0, ',', '.');
            }
        }

        return [] === $parts ? '–' : implode(', ', $parts);
    }

    /** @param array<string, int|float> $goods resource or ship values => amounts */
    public static function goods(array $goods): string
    {
        $parts = [];
        foreach ($goods as $good => $amount) {
            if ($amount >= 1) {
                $label = ResourceType::tryFrom((string) $good)?->label() ?? ShipType::tryFrom((string) $good)?->label() ?? (string) $good;
                $parts[] = number_format((float) $amount, 0, ',', '.').' '.$label;
            }
        }

        return [] === $parts ? '–' : implode(', ', $parts);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
