<?php

namespace App\Game;

use App\Entity\AttackBan;
use App\Repository\AttackBanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * General attack ban: while one is active nobody may attack, intercept or raid, and attacks
 * already flying turn back on arrival. Spying, transports and everything else go on.
 */
final readonly class AttackBans
{
    public const int MAX_HOURS = 24 * 14;

    public function __construct(
        private EntityManagerInterface $em,
        private AttackBanRepository $bans,
        private ClockInterface $clock,
    ) {
    }

    public function current(): ?AttackBan
    {
        $now = $this->now();
        foreach ($this->bans->findNotLifted() as $ban) {
            if ($ban->isActive($now)) {
                return $ban;
            }
        }

        return null;
    }

    /** Why attacks are refused right now, or null. */
    public function refusal(): ?TranslatableInterface
    {
        $ban = $this->current();
        if (null === $ban) {
            return null;
        }

        return null === $ban->getEndsAt()
            ? t('Die Spielleitung hat eine allgemeine Angriffssperre verhängt: {reason}', ['{reason}' => $ban->getReason()])
            : t('Die Spielleitung hat bis {endsAt} eine allgemeine Angriffssperre verhängt: {reason}', ['{reason}' => $ban->getReason(), '{endsAt}' => $ban->getEndsAt()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i')]);
    }

    /** @param int|null $hours null: until it is lifted */
    public function impose(string $reason, ?int $hours): AttackBan
    {
        if ('' === trim($reason)) {
            throw new GameException(t('Gib einen Grund für die Angriffssperre an – die Spieler sehen ihn.'));
        }
        if (null !== $hours && ($hours < 1 || $hours > self::MAX_HOURS)) {
            throw new GameException(t('Eine befristete Sperre dauert 1 bis {max} Stunden.', ['{max}' => self::MAX_HOURS]));
        }
        $now = $this->now();
        // A new ban replaces a running one.
        $this->current()?->lift($now);
        $ban = new AttackBan($reason, $now, null === $hours ? null : $now->modify('+'.$hours.' hours'));
        $this->em->persist($ban);
        $this->em->flush();

        return $ban;
    }

    public function lift(): void
    {
        $this->current()?->lift($this->now());
        $this->em->flush();
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
