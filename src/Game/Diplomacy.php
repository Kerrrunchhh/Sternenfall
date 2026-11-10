<?php

namespace App\Game;

use App\Entity\Alliance;
use App\Entity\DiplomacyProposal;
use App\Entity\DiplomaticRelation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use App\I18n\Lines;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

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
                throw new GameException(t('Mit der eigenen Allianz gibt es keine Verträge.'));
            }
            $current = $this->relation($own, $target);
            if ($current?->getKind() === $kind) {
                throw new GameException(t('Mit „{name}“ besteht bereits: {kind}.', ['{name}' => $target->getName(), '{kind}' => t($kind->label())]));
            }
            if (TreatyKind::War === $current?->getKind()) {
                throw new GameException(t('Ihr seid mit „{name}“ im Krieg. Schließt erst Frieden (Krieg beenden), dann sind Verträge möglich.', ['{name}' => $target->getName()]));
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
                throw new GameException(t('Diese Beziehung betrifft deine Allianz nicht.'));
            }
            $other = $relation->other($own);
            $this->assertNoOpenProposal($own, $other);

            return $this->open(new DiplomacyProposal($own, $other, $relation->getKind(), true, $actor, $this->now(), $message), $actor);
        });
    }

    public function vote(User $actor, DiplomacyProposal $proposal, bool $yes): TranslatableInterface
    {
        return $this->locked(function () use ($actor, $proposal, $yes): TranslatableInterface {
            $alliance = $proposal->votingAlliance();
            if (!self::canDecide($actor) || $actor->getAlliance()?->getId() !== $alliance->getId()) {
                throw new GameException(t('Hierüber stimmen gerade Gründer und Kommandanten von „{name}“ ab.', ['{name}' => $alliance->getName()]));
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
        $this->notify(self::voters($proposal->getProposer()), t('Abstimmung: {title} mit {name}', ['{title}' => t($proposal->title()), '{name}' => $proposal->getTarget()->getName()]), new Lines([t('{player} schlägt vor: {title} mit „{name}“ [{tag}]. Stimme unter Diplomatie ab.', ['{player}' => $actor->getUsername(), '{title}' => t($proposal->title()), '{name}' => $proposal->getTarget()->getName(), '{tag}' => $proposal->getTarget()->getTag()]), $proposal->quotedMessage()], ''), $actor);
        $this->evaluate($proposal);

        return $proposal;
    }

    /** Applies the votes of the current stage: passed, rejected or still open. Returns a short result. */
    private function evaluate(DiplomacyProposal $proposal): TranslatableInterface
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
            $result = t('abgelehnt');
        } else {
            $result = t('Stimme gezählt ({yes} Ja, {no} Nein, {needed} nötig).', ['{yes}' => $yes, '{no}' => $no, '{needed}' => $needed]);
        }
        $this->em->flush();

        return $result;
    }

    private function pass(DiplomacyProposal $proposal): TranslatableInterface
    {
        $own = $proposal->getProposer();
        $other = $proposal->getTarget();
        $kind = $proposal->getKind();
        $bothSides = $proposal->isEnding() ? TreatyKind::War === $kind : $kind->needsBothSides();

        if (DiplomacyProposal::STAGE_OWN === $proposal->getStage() && $bothSides) {
            $proposal->toOtherSide();
            $this->notify(self::voters($other), t('{title}: Vorschlag von {name}', ['{title}' => t($proposal->title()), '{name}' => $own->getName()]), new Lines([t('„{name}“ [{tag}] schlägt euch vor: {title}. Gründer und Kommandanten stimmen unter Diplomatie ab.', ['{name}' => $own->getName(), '{tag}' => $own->getTag(), '{title}' => t($proposal->title())]), $proposal->quotedMessage()], ''));
            $this->notify(self::voters($own), t('{title}: angenommen', ['{title}' => t($proposal->title())]), t('Eure Führung hat zugestimmt: {title} mit „{name}“. Jetzt stimmt „{name}“ ab.', ['{title}' => t($proposal->title()), '{name}' => $other->getName()]));

            return t('Angenommen – jetzt stimmt „{name}“ ab.', ['{name}' => $other->getName()]);
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
            ? t('{kind} zwischen „{name}“ und „{name2}“ ist beendet.', ['{kind}' => t($kind->label()), '{name}' => $own->getName(), '{name2}' => $other->getName()])
            : t('Ab sofort gilt zwischen „{name}“ und „{name2}“: {kind}. {effect}', ['{name}' => $own->getName(), '{name2}' => $other->getName(), '{kind}' => t($kind->label()), '{effect}' => t($kind->effect())]);
        $text = new Lines([$text, $proposal->quotedMessage()], '');
        if (TreatyKind::War === $kind && !$proposal->isEnding()) {
            $text = t('Kriegserklärung: „{name}“ [{tag}] erklärt „{name2}“ [{tag2}] den Krieg. {effect}', ['{name}' => $own->getName(), '{tag}' => $own->getTag(), '{name2}' => $other->getName(), '{tag2}' => $other->getTag(), '{effect}' => t($kind->effect())]);
            $text = new Lines([$text, $proposal->quotedMessage()], '');
        }
        foreach ([$own, $other] as $alliance) {
            $partner = $alliance->getId() === $own->getId() ? $other : $own;
            $subject = t('{title} – {name}', ['{title}' => t($proposal->isEnding() ? $proposal->title() : $kind->label()), '{name}' => $partner->getName()]);
            $this->notify($alliance->getMembers()->toArray(), $subject, $text);
        }

        return $proposal->isEnding() ? t('Beendet.') : t('In Kraft: {kind}.', ['{kind}' => t($kind->label())]);
    }

    private function reject(DiplomacyProposal $proposal): void
    {
        $by = $proposal->votingAlliance();
        $this->notify(self::voters($proposal->getProposer()), t('{title}: abgelehnt', ['{title}' => t($proposal->title())]), t('„{name}“ hat abgelehnt: {title} zwischen „{name2}“ und „{name3}“.', ['{name}' => $by->getName(), '{title}' => t($proposal->title()), '{name2}' => $proposal->getProposer()->getName(), '{name3}' => $proposal->getTarget()->getName()]));
        $this->em->remove($proposal);
    }

    private function assertNoOpenProposal(Alliance $a, Alliance $b): void
    {
        $open = $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')->from(DiplomacyProposal::class, 'p')
            ->where('(p.proposer = :a AND p.target = :b) OR (p.proposer = :b AND p.target = :a)')
            ->setParameter('a', $a)->setParameter('b', $b)->getQuery()->getSingleScalarResult();
        if ($open > 0) {
            throw new GameException(t('Mit „{name}“ läuft bereits eine Abstimmung.', ['{name}' => $b->getName()]));
        }
    }

    private function leadAlliance(User $actor): Alliance
    {
        $alliance = $actor->getAlliance();
        if (null === $alliance || !self::canDecide($actor)) {
            throw new GameException(t('Diplomatie machen nur Gründer und Kommandanten einer Allianz.'));
        }

        return $alliance;
    }

    /** @param iterable<User> $recipients */
    private function notify(iterable $recipients, TranslatableInterface $subject, TranslatableInterface $body, ?User $except = null): void
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
