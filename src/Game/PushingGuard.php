<?php

namespace App\Game;

use App\Entity\ResourceGift;
use App\Entity\User;
use App\I18n\Num;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\Translation\t;

/**
 * Pushing protection: a smaller player may give a bigger one at most a quarter of his weekly
 * production within 7 days (transports without payment, and credits paid to him – counted at
 * their worth in resources). Paid transport requests and trade at trade posts don't count.
 * Every gift is logged for the admins.
 */
final readonly class PushingGuard
{
    public const float SHARE_OF_WEEKLY_PRODUCTION = 0.25;
    public const int DAYS = 7;
    /** What a credit is worth in resources: the trade guild values iron, the main resource, at 2 credits. */
    public const float CREDITS_PER_RESOURCE = 2.0;

    public function __construct(
        private EntityManagerInterface $em,
        private Economy $economy,
    ) {
    }

    /** Throws if the transport would exceed the limit; otherwise logs it as a gift. */
    public function checkAndRecord(User $sender, User $receiver, float $amount, \DateTimeImmutable $now): void
    {
        if ($amount < 1 || $sender->isSameAs($receiver) || $receiver->isSystem()) {
            return;
        }
        if (Scoring::points($receiver) > Scoring::points($sender)) {
            $limit = floor(self::SHARE_OF_WEEKLY_PRODUCTION * $this->weeklyProduction($sender));
            $given = $this->givenTo($sender, $receiver, $now->modify('-'.self::DAYS.' days'));
            if ($given + $amount > $limit) {
                throw new GameException(t('Pushing-Schutz: {player} hat mehr Punkte als du. Ohne Gegenleistung darfst du ihm in 7 Tagen höchstens {limit} Rohstoffe schicken (ein Viertel deiner Wochenproduktion), {given} hast du schon geschickt. Für Lieferungen gegen Bezahlung gibt es Handelsstützpunkte und Transportgesuche.', [
                    '{player}' => $receiver->getUsername(),
                    '{limit}' => new Num($limit),
                    '{given}' => new Num($given),
                ]));
            }
        }
        $this->em->persist(new ResourceGift($sender, $receiver, $amount, $now));
    }

    /** A credit payment: the same limit, counted at the credits' worth in resources; logged for the admins. */
    public function checkAndRecordCredits(User $sender, User $receiver, int $credits, \DateTimeImmutable $now): void
    {
        $worth = $credits / self::CREDITS_PER_RESOURCE;
        if ($credits < 1 || $sender->isSameAs($receiver) || $receiver->isSystem()) {
            return;
        }
        if (Scoring::points($receiver) > Scoring::points($sender)) {
            $limit = floor(self::SHARE_OF_WEEKLY_PRODUCTION * $this->weeklyProduction($sender));
            $given = $this->givenTo($sender, $receiver, $now->modify('-'.self::DAYS.' days'));
            if ($given + $worth > $limit) {
                throw new GameException(t('Pushing-Schutz: {player} hat mehr Punkte als du. In 7 Tagen darfst du ihm Rohstoffe und Credits im Wert von höchstens {limit} Rohstoffen schenken (ein Viertel deiner Wochenproduktion, {rate} Credits zählen wie 1 Rohstoff). Davon hast du schon {given} verbraucht – möglich sind noch {left} Credits.', [
                    '{player}' => $receiver->getUsername(),
                    '{limit}' => new Num($limit),
                    '{rate}' => new Num(self::CREDITS_PER_RESOURCE),
                    '{given}' => new Num($given),
                    '{left}' => new Num(max(0, floor(($limit - $given) * self::CREDITS_PER_RESOURCE))),
                ]));
            }
        }
        $this->em->persist(new ResourceGift($sender, $receiver, $worth, $now, $credits));
    }

    public function weeklyProduction(User $player): float
    {
        $perHour = 0.0;
        foreach ($player->getPlanets() as $planet) {
            $perHour += $this->economy->productionPerHour($planet)->total();
        }

        return 168 * $perHour;
    }

    private function givenTo(User $sender, User $receiver, \DateTimeImmutable $since): float
    {
        return (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM resource_gift WHERE sender_id = ? AND receiver_id = ? AND created_at >= ?',
            [$sender->getId(), $receiver->getId(), $since->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        );
    }
}
