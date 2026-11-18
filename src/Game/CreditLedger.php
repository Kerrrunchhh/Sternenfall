<?php

namespace App\Game;

use App\Entity\CreditBooking;
use App\Entity\TradePost;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Every player's credit statement: payments, trades, treasury, transport requests, contracts,
 * bounties and the fusionator market – in and out, with the other party and the purpose.
 * Trade bookings name the trade post, so its owner sees what he sold and bought and to whom.
 * Kept KEEP_DAYS.
 */
final readonly class CreditLedger
{
    public const int KEEP_DAYS = 90;

    public const string TRANSFER = 'transfer';
    public const string TREASURY = 'treasury';
    public const string TRADE = 'trade';
    public const string TRANSPORT = 'transport';
    public const string CONTRACT = 'contract';
    public const string BOUNTY = 'bounty';
    public const string FUSIONATOR = 'fusionator';

    /** Labels for the statement. */
    public const array KINDS = [
        self::TRANSFER => 'Überweisung',
        self::TREASURY => 'Allianzkasse',
        self::TRADE => 'Handel',
        self::TRANSPORT => 'Transportgesuch',
        self::CONTRACT => 'Auftrag',
        self::BOUNTY => 'Kopfgeld',
        self::FUSIONATOR => 'Fusionator-Markt',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Books credits for a player (persisted, flushed by the caller). Amounts below 1 and system
     * accounts (trade guild, ownerless) are not booked; a system counterparty is named instead.
     *
     * @param array<string, float> $goods
     */
    public function book(User $user, float $amount, string $kind, ?User $counterparty, string $purpose, ?TradePost $post = null, array $goods = [], ?string $counterpartyName = null): void
    {
        if ($user->isSystem() || abs($amount) < 1) {
            return;
        }
        $player = null !== $counterparty && !$counterparty->isSystem() && !$counterparty->isSameAs($user) ? $counterparty : null;
        $name = $counterpartyName ?? (null !== $counterparty && $counterparty->isSystem() ? $counterparty->getUsername() : null);
        $this->em->persist(new CreditBooking($user, round($amount, 2), $kind, $player, $name, mb_substr(trim($purpose), 0, 1000), $post, array_filter($goods), $this->now()));
        // Old bookings go now and then (a cheap indexed delete).
        if (1 === random_int(1, 200)) {
            $this->em->getConnection()->executeStatement('DELETE FROM credit_booking WHERE created_at < ?', [$this->now()->modify('-'.self::KEEP_DAYS.' days')->format('Y-m-d H:i:s')]);
        }
    }

    /**
     * The player's statement, newest first.
     *
     * @return list<CreditBooking>
     */
    public function statement(User $player, ?string $direction = null, ?string $kind = null, int $limit = 500): array
    {
        $qb = $this->em->createQueryBuilder()->select('b')->from(CreditBooking::class, 'b')
            ->where('b.user = :p')->setParameter('p', $player)
            ->orderBy('b.createdAt', 'DESC')->addOrderBy('b.id', 'DESC')
            ->setMaxResults($limit);
        if ('in' === $direction) {
            $qb->andWhere('b.amount > 0');
        } elseif ('out' === $direction) {
            $qb->andWhere('b.amount < 0');
        }
        if (null !== $kind) {
            $qb->andWhere('b.kind = :kind')->setParameter('kind', $kind);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Sales report of a trader's posts: per post and trading partner the goods he sold and
     * bought there and the credits, over the last $days days.
     *
     * @return list<array{post: TradePost, partner: string, sold: array<string, float>, bought: array<string, float>, income: float, spent: float, deals: int}>
     */
    public function postReport(User $trader, int $days = self::KEEP_DAYS): array
    {
        /** @var list<CreditBooking> $bookings */
        $bookings = $this->em->createQueryBuilder()->select('b')->from(CreditBooking::class, 'b')
            ->join('b.tradePost', 'p')
            ->where('b.user = :t AND p.owner = :t AND b.kind = :trade AND b.createdAt >= :since')
            ->setParameter('t', $trader)->setParameter('trade', self::TRADE)
            ->setParameter('since', $this->now()->modify("-{$days} days"))
            ->getQuery()->getResult();
        $rows = [];
        foreach ($bookings as $b) {
            $partner = $b->getCounterparty()?->getUsername() ?? $b->getCounterpartyName() ?? '–';
            $key = $b->getTradePost()->getId().'|'.$partner;
            $rows[$key] ??= ['post' => $b->getTradePost(), 'partner' => $partner, 'sold' => [], 'bought' => [], 'income' => 0.0, 'spent' => 0.0, 'deals' => 0];
            // The trader received credits: he sold these goods; he paid: he bought them.
            $side = $b->getAmount() > 0 ? 'sold' : 'bought';
            foreach ($b->getGoods() as $good => $amount) {
                $rows[$key][$side][$good] = ($rows[$key][$side][$good] ?? 0) + $amount;
            }
            $b->getAmount() > 0 ? $rows[$key]['income'] += $b->getAmount() : $rows[$key]['spent'] -= $b->getAmount();
            ++$rows[$key]['deals'];
        }
        usort($rows, static fn (array $a, array $b): int => [$a['post']->getName(), -$a['income']] <=> [$b['post']->getName(), -$b['income']]);

        return $rows;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
