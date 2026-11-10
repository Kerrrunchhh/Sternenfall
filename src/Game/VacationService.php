<?php

namespace App\Game;

use App\Entity\User;
use App\Repository\FleetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Vacation: 1 to 30 days (fractions allowed). Meanwhile everything rests – production,
 * building, research, stay limits – and nobody can attack the player's planets (spying is
 * allowed), except invaded planets that don't fully belong to him yet. The player can only
 * talk and look around (see VacationListener); he may end it early after one day. Admins impose vacation as a penalty,
 * which can't be ended early.
 */
final readonly class VacationService
{
    public const float MIN_DAYS = 1.0;
    public const float MAX_DAYS = 30.0;

    public function __construct(
        private EntityManagerInterface $em,
        private PlanetService $planets,
        private FleetRepository $fleets,
        private UserRepository $users,
        private Inbox $inbox,
        private LockFactory $lockFactory,
        private ClockInterface $clock,
    ) {
    }

    public static function parseDays(string $input): float
    {
        $input = str_replace(',', '.', trim($input));
        if (!preg_match('/^\d+(\.\d+)?$/', $input)) {
            throw new GameException('Gib die Urlaubstage als Zahl ein, z. B. 3 oder 2,5.');
        }

        return (float) $input;
    }

    public function start(User $user, float $days): \DateTimeImmutable
    {
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new GameException('Urlaub geht von 1 bis 30 Tagen (auch mit Komma, z. B. 2,5).');
        }
        foreach ($this->fleets->findByOwner($user) as $fleet) {
            if ($fleet->isMoving()) {
                throw new GameException(\sprintf('%s ist noch unterwegs. In den Urlaub geht es erst, wenn alle deine Flotten gelandet sind.', $fleet->getLabel()));
            }
        }

        return $this->begin($user, $days, false, null);
    }

    /** A penalty imposed by an admin: vacation that can't be ended early. */
    public function impose(User $user, float $days, string $reason): \DateTimeImmutable
    {
        if ($days <= 0 || $days > self::MAX_DAYS) {
            throw new GameException('Eine Strafe dauert mehr als 0 und höchstens 30 Tage.');
        }
        $now = $this->now();
        if ($user->isOnVacation($now)) {
            $this->finish($user, $now);
        }
        $until = $this->begin($user, $days, true, $reason);
        $this->inbox->send($user, 'Strafe: Urlaub bis '.$until->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i'), \sprintf("Die Spielleitung hat dich für %s Tage in den Urlaub geschickt.\nGrund: %s", str_replace('.', ',', (string) round($days, 2)), $reason));
        $this->em->flush();

        return $until;
    }

    public function endEarly(User $user): void
    {
        $now = $this->now();
        if (!$user->isOnVacation($now)) {
            throw new GameException('Du bist nicht im Urlaub.');
        }
        if ($user->isVacationForced()) {
            throw new GameException('Diesen Urlaub hat die Spielleitung verhängt – er kann nicht vorzeitig beendet werden.');
        }
        $earliest = $user->getVacationSince()->modify('+1 day');
        if ($now < $earliest) {
            throw new GameException('Urlaub dauert mindestens einen Tag. Beenden kannst du ihn ab '.$earliest->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i').'.');
        }
        $this->finish($user, $now);
    }

    /** Ends the vacation once its time is up (called lazily). */
    public function finishIfOver(User $user): void
    {
        $until = $user->getVacationUntil();
        if (null !== $until && $until <= $this->now()) {
            $this->finish($user, $until);
        }
    }

    public function finishAllOver(): void
    {
        foreach ($this->users->createQueryBuilder('u')->where('u.vacationUntil <= :now')->setParameter('now', $this->now())->getQuery()->getResult() as $user) {
            $this->finishIfOver($user);
        }
    }

    private function begin(User $user, float $days, bool $forced, ?string $reason): \DateTimeImmutable
    {
        $now = $this->now();
        $until = $now->modify('+'.(int) round($days * 86400).' seconds');
        // Bring every planet up to date first; from now on its clock stands still.
        foreach ($user->getPlanets()->toArray() as $planet) {
            $this->planets->transaction($planet, static fn () => null);
        }
        $this->locked($user, function () use ($user, $now, $until, $forced, $reason): void {
            $user->startVacation($now, $until, $forced, $reason);
            $this->em->flush();
        });

        return $until;
    }

    /** All clocks (production, queues, research, stay limits) go on as if no time had passed. */
    private function finish(User $user, \DateTimeImmutable $at): void
    {
        $this->locked($user, function () use ($user, $at): void {
            $this->em->refresh($user);
            if (null === $user->getVacationSince()) {
                return;
            }
            $seconds = $user->endVacation($at);
            foreach ($user->getPlanets() as $planet) {
                $planet->shiftClock($seconds);
            }
            foreach ($this->fleets->findByOwner($user) as $fleet) {
                $fleet->shiftParking($seconds);
            }
            $this->em->flush();
        });
    }

    private function locked(User $user, callable $operation): void
    {
        $lock = $this->lockFactory->createLock('player_'.$user->getId());
        $lock->acquire(true);
        try {
            $operation();
        } finally {
            $lock->release();
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
