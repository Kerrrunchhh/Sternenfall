<?php

namespace App\Game;

use App\Entity\Alliance;
use App\Entity\DiplomacyProposal;
use App\Entity\DiplomaticRelation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Bonds, non-aggression pacts and wars between alliances. Founder and commanders
 * propose and vote; a majority of them decides. Bond and NAP then go to the other
 * alliance, which has to agree as well; war is declared by one side. Bond and NAP end
 * as soon as one side votes for it; a war only ends when both sides vote for peace.
 */
final class Diplomacy
{
    /** @var array<string, ?DiplomaticRelation> cached per request: "a:b" => relation */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Inbox $inbox,
        private readonly LockFactory $lockFactory,
        private readonly ClockInterface $clock,
    ) {
    }

    // ------------------------------------------------------------------
    // Effects
    // ------------------------------------------------------------------

    public function relation(Alliance $a, Alliance $b): ?DiplomaticRelation
    {
        if ($a->getId() === $b->getId()) {
            return null;
        }
        $key = min($a->getId(), $b->getId()).':'.max($a->getId(), $b->getId());
        if (!\array_key_exists($key, $this->cache)) {
            $this->cache[$key] = $this->em->createQueryBuilder()
                ->select('r')->from(DiplomaticRelation::class, 'r')
                ->where('(r.first = :a AND r.second = :b) OR (r.first = :b AND r.second = :a)')
                ->setParameter('a', $a)->setParameter('b', $b)
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        }

        return $this->cache[$key];
    }

    /** The relation between the alliances of two players (none if one has no alliance or both share one). */
    public function kindBetween(User $a, User $b): ?TreatyKind
    {
        $first = $a->getAlliance();
        $second = $b->getAlliance();

        return null === $first || null === $second ? null : $this->relation($first, $second)?->getKind();
    }

    /** Own players, alliance mates and partners of a bond or NAP are no targets. */
    public function mayAttack(User $attacker, User $victim): bool
    {
        if ($attacker->isSameAs($victim) || $attacker->isAllyOf($victim)) {
            return false;
        }

        return !($this->kindBetween($attacker, $victim)?->forbidsAttacks() ?? false);
    }

    /** Fleets may stay for good on own planets, those of alliance mates and of bond partners. */
    public function mayStay(User $fleetOwner, User $planetOwner): bool
    {
        return $fleetOwner->isSameAs($planetOwner) || $fleetOwner->isAllyOf($planetOwner)
            || TreatyKind::Bond === $this->kindBetween($fleetOwner, $planetOwner);
    }

    public function atWar(User $a, User $b): bool
    {
        return TreatyKind::War === $this->kindBetween($a, $b);
    }

    // ------------------------------------------------------------------
    // Proposals and votes
    // ------------------------------------------------------------------

    /** Founder and commanders: they propose and vote. */
    public static function canDecide(User $user): bool
    {
        return \in_array($user->getAllianceRank(), [AllianceRank::Founder, AllianceRank::Commander], true);
    }

    /** @return list<User> */
    public static function voters(Alliance $alliance): array
    {
        return array_values(array_filter($alliance->getMembers()->toArray(), self::canDecide(...)));
    }

    public function propose(User $actor, Alliance $target, TreatyKind $kind, string $message = ''): DiplomacyProposal
    {
        return $this->locked(function () use ($actor, $target, $kind, $message): DiplomacyProposal {
            $own = $this->leadAlliance($actor);
            if ($own->getId() === $target->getId()) {
                throw new GameException('Mit der eigenen Allianz gibt es keine Verträge.');
            }
            $current = $this->relation($own, $target);
            if ($current?->getKind() === $kind) {
                throw new GameException(\sprintf('Mit „%s“ besteht bereits: %s.', $target->getName(), $kind->label()));
            }
            if (TreatyKind::War === $current?->getKind()) {
                throw new GameException(\sprintf('Ihr seid mit „%s“ im Krieg. Schließt erst Frieden (Krieg beenden), dann sind Verträge möglich.', $target->getName()));
            }
            $this->assertNoOpenProposal($own, $target);

            return $this->open(new DiplomacyProposal($own, $target, $kind, false, $actor, $this->now(), $message), $actor);
        });
    }

    public function proposeEnd(User $actor, DiplomaticRelation $relation, string $message = ''): DiplomacyProposal
    {
        return $this->locked(function () use ($actor, $relation, $message): DiplomacyProposal {
            $own = $this->leadAlliance($actor);
            if (!$relation->involves($own)) {
                throw new GameException('Diese Beziehung betrifft deine Allianz nicht.');
            }
            $other = $relation->other($own);
            $this->assertNoOpenProposal($own, $other);

            return $this->open(new DiplomacyProposal($own, $other, $relation->getKind(), true, $actor, $this->now(), $message), $actor);
        });
    }

    public function vote(User $actor, DiplomacyProposal $proposal, bool $yes): string
    {
        return $this->locked(function () use ($actor, $proposal, $yes): string {
            $alliance = $proposal->votingAlliance();
            if (!self::canDecide($actor) || $actor->getAlliance()?->getId() !== $alliance->getId()) {
                throw new GameException('Hierüber stimmen gerade Gründer und Kommandanten von „'.$alliance->getName().'“ ab.');
            }
            $proposal->vote($actor, $yes);

            return $this->evaluate($proposal);
        });
    }

    /** @return list<DiplomaticRelation> */
    public function relationsOf(Alliance $alliance): array
    {
        return $this->em->createQueryBuilder()
            ->select('r')->from(DiplomaticRelation::class, 'r')
            ->where('r.first = :a OR r.second = :a')->setParameter('a', $alliance)
            ->orderBy('r.since', 'DESC')->getQuery()->getResult();
    }

    /** @return list<DiplomacyProposal> open proposals the alliance is part of */
    public function proposalsOf(Alliance $alliance): array
    {
        return $this->em->createQueryBuilder()
            ->select('p')->from(DiplomacyProposal::class, 'p')
            ->where('p.proposer = :a OR (p.target = :a AND p.stage = :other)')
            ->setParameter('a', $alliance)->setParameter('other', DiplomacyProposal::STAGE_OTHER)
            ->orderBy('p.createdAt', 'ASC')->getQuery()->getResult();
    }

    /** Open proposals the player may vote on now and has not voted on yet. */
    public function pendingVotes(User $user): int
    {
        $alliance = $user->getAlliance();
        if (null === $alliance || !self::canDecide($user)) {
            return 0;
        }

        return \count(array_filter(
            $this->proposalsOf($alliance),
            static fn (DiplomacyProposal $p): bool => $p->votingAlliance()->getId() === $alliance->getId() && !\array_key_exists((string) $user->getId(), $p->votes()),
        ));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private function open(DiplomacyProposal $proposal, User $actor): DiplomacyProposal
    {
        $this->em->persist($proposal);
        // The one who proposes votes yes right away.
        $proposal->vote($actor, true);
        $this->notify(self::voters($proposal->getProposer()), 'Abstimmung: '.$proposal->title().' mit '.$proposal->getTarget()->getName(), \sprintf('%s schlägt vor: %s mit „%s“ [%s]. Stimme unter Diplomatie ab.', $actor->getUsername(), $proposal->title(), $proposal->getTarget()->getName(), $proposal->getTarget()->getTag()).$proposal->quotedMessage(), $actor);
        $this->evaluate($proposal);

        return $proposal;
    }

    /** Applies the votes of the current stage: passed, rejected or still open. Returns a short result. */
    private function evaluate(DiplomacyProposal $proposal): string
    {
        $voters = self::voters($proposal->votingAlliance());
        $ids = array_map(static fn (User $u): string => (string) $u->getId(), $voters);
        $votes = array_intersect_key($proposal->votes(), array_flip($ids));
        $yes = \count(array_filter($votes));
        $no = \count($votes) - $yes;
        $needed = intdiv(\count($voters), 2) + 1;

        if ($yes >= $needed) {
            $result = $this->pass($proposal);
        } elseif ($no >= \count($voters) - $needed + 1 || \count($votes) >= \count($voters)) {
            $this->reject($proposal);
            $result = 'abgelehnt';
        } else {
            $result = \sprintf('Stimme gezählt (%d Ja, %d Nein, %d nötig).', $yes, $no, $needed);
        }
        $this->em->flush();

        return $result;
    }

    private function pass(DiplomacyProposal $proposal): string
    {
        $own = $proposal->getProposer();
        $other = $proposal->getTarget();
        $kind = $proposal->getKind();
        $bothSides = $proposal->isEnding() ? TreatyKind::War === $kind : $kind->needsBothSides();

        if (DiplomacyProposal::STAGE_OWN === $proposal->getStage() && $bothSides) {
            $proposal->toOtherSide();
            $this->notify(self::voters($other), \sprintf('%s: Vorschlag von %s', $proposal->title(), $own->getName()), \sprintf('„%s“ [%s] schlägt euch vor: %s. Gründer und Kommandanten stimmen unter Diplomatie ab.', $own->getName(), $own->getTag(), $proposal->title()).$proposal->quotedMessage());
            $this->notify(self::voters($own), $proposal->title().': angenommen', \sprintf('Eure Führung hat zugestimmt: %s mit „%s“. Jetzt stimmt „%s“ ab.', $proposal->title(), $other->getName(), $other->getName()));

            return 'Angenommen – jetzt stimmt „'.$other->getName().'“ ab.';
        }

        $current = $this->relation($own, $other);
        if (null !== $current) {
            $this->em->remove($current);
        }
        if (!$proposal->isEnding()) {
            $this->em->persist(new DiplomaticRelation($own, $other, $kind, $this->now()));
        }
        $this->cache = [];
        $this->em->remove($proposal);
        $text = $proposal->isEnding()
            ? \sprintf('%s zwischen „%s“ und „%s“ ist beendet.', $kind->label(), $own->getName(), $other->getName())
            : \sprintf('Ab sofort gilt zwischen „%s“ und „%s“: %s. %s', $own->getName(), $other->getName(), $kind->label(), $kind->effect());
        $text .= $proposal->quotedMessage();
        if (TreatyKind::War === $kind && !$proposal->isEnding()) {
            $text = \sprintf('Kriegserklärung: „%s“ [%s] erklärt „%s“ [%s] den Krieg. %s', $own->getName(), $own->getTag(), $other->getName(), $other->getTag(), $kind->effect()).$proposal->quotedMessage();
        }
        foreach ([$own, $other] as $alliance) {
            $partner = $alliance->getId() === $own->getId() ? $other : $own;
            $subject = ($proposal->isEnding() ? $proposal->title() : $kind->label()).' – '.$partner->getName();
            $this->notify($alliance->getMembers()->toArray(), $subject, $text);
        }

        return $proposal->isEnding() ? 'Beendet.' : 'In Kraft: '.$kind->label().'.';
    }

    private function reject(DiplomacyProposal $proposal): void
    {
        $by = $proposal->votingAlliance();
        $this->notify(self::voters($proposal->getProposer()), $proposal->title().': abgelehnt', \sprintf('„%s“ hat abgelehnt: %s zwischen „%s“ und „%s“.', $by->getName(), $proposal->title(), $proposal->getProposer()->getName(), $proposal->getTarget()->getName()));
        $this->em->remove($proposal);
    }

    private function assertNoOpenProposal(Alliance $a, Alliance $b): void
    {
        $open = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')->from(DiplomacyProposal::class, 'p')
            ->where('(p.proposer = :a AND p.target = :b) OR (p.proposer = :b AND p.target = :a)')
            ->setParameter('a', $a)->setParameter('b', $b)->getQuery()->getSingleScalarResult();
        if ($open > 0) {
            throw new GameException(\sprintf('Mit „%s“ läuft bereits eine Abstimmung.', $b->getName()));
        }
    }

    private function leadAlliance(User $actor): Alliance
    {
        $alliance = $actor->getAlliance();
        if (null === $alliance || !self::canDecide($actor)) {
            throw new GameException('Diplomatie machen nur Gründer und Kommandanten einer Allianz.');
        }

        return $alliance;
    }

    /** @param iterable<User> $recipients */
    private function notify(iterable $recipients, string $subject, string $body, ?User $except = null): void
    {
        foreach ($recipients as $user) {
            if (null === $except || !$user->isSameAs($except)) {
                $this->inbox->send($user, $subject, $body);
            }
        }
    }

    private function locked(callable $operation): mixed
    {
        $lock = $this->lockFactory->createLock('alliance_membership');
        $lock->acquire(true);
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
