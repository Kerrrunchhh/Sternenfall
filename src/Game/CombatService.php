<?php

namespace App\Game;

use App\Entity\DebrisField;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\User;
use App\Repository\DebrisFieldRepository;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Resolves combat when fleets arrive: attacks on planets, interceptions,
 * raids on transports and salvaging debris. The caller (fleet processing) holds
 * the fleet event lock; planet changes run under the planet owner's lock.
 */
final readonly class CombatService
{
    /** Inhabitants outside the bunkers one heavy bomber kills. */
    public const int BOMBER_KILLS = 50;

    public function __construct(
        private EntityManagerInterface $em,
        private PlanetService $planets,
        private FleetRepository $fleets,
        private DebrisFieldRepository $debris,
        private Economy $economy,
        private Inbox $inbox,
        private ClockInterface $clock,
    ) {
    }

    public function attackPlanet(Fleet $attacker, Planet $target): void
    {
        $this->planets->transaction($target, function (Planet $target, \DateTimeImmutable $now) use ($attacker): void {
            $defender = $target->getOwner();
            [$g, $s, $p] = [$attacker->getTargetGalaxy(), $attacker->getTargetSystem(), $attacker->getTargetPosition()];

            // Defenders: hangar and turrets, plus waiting fleets of the owner and his alliance.
            $owners = [$defender];
            foreach ($defender->getAlliance()?->getMembers() ?? [] as $member) {
                if (!$member->isSameAs($defender)) {
                    $owners[] = $member;
                }
            }
            $guards = $this->fleets->findIdleOf($g, $s, $p, $owners);

            $battle = new Battle();
            $battle->add(BattleGroup::ATTACKER, $attacker->getOwner(), $attacker->getShips(), 'attacker');
            $battle->add(BattleGroup::DEFENDER, $defender, $target->getShips(), 'hangar');
            foreach ($guards as $guard) {
                $battle->add(BattleGroup::DEFENDER, $guard->getOwner(), $guard->getShips(), 'fleet:'.$guard->getId());
            }
            $result = $battle->fight();

            $attacker->replaceShips($result->survivors(BattleGroup::ATTACKER, 'attacker'));
            $target->replaceShips($result->survivors(BattleGroup::DEFENDER, 'hangar'));
            foreach ($guards as $guard) {
                $guard->replaceShips($result->survivors(BattleGroup::DEFENDER, 'fleet:'.$guard->getId()));
                if (!$guard->hasShips()) {
                    $this->fleets->forget($guard);
                }
            }
            $outcome = [$this->leaveDebris($g, $s, $p, $result)];

            if ($result->attackerWon()) {
                $outcome = [...$outcome, ...$this->plunder($attacker, $target, $result, $now)];
                $headline = 'Der Angreifer hat gewonnen.';
            } else {
                $headline = $attacker->hasShips() ? 'Unentschieden: die Verteidigung hält.' : 'Der Angreifer wurde vernichtet.';
            }

            $subject = \sprintf('Kampfbericht %s [%s]', $target->getName(), $target->getCoordinates());
            $body = implode("\n", [$headline, '', $result->report(), '', ...array_filter($outcome)]);
            $this->notify([$attacker->getOwner(), $defender, ...array_map(static fn (Fleet $f): User => $f->getOwner(), $guards)], $subject, $body);
        });
    }

    /** A Wächter or Pirat fleet meets an attacking fleet just before it arrives. */
    public function intercept(Fleet $hunter, Fleet $prey): void
    {
        $this->fleetBattle($hunter, $prey, raid: false);
    }

    /** A Pirat fleet raids a fleet transporting resources. */
    public function raid(Fleet $hunter, Fleet $prey): void
    {
        $this->fleetBattle($hunter, $prey, raid: true);
    }

    /** Salvagers collect a debris field. */
    public function salvage(Fleet $fleet): string
    {
        $field = $this->debris->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition());
        if (null === $field) {
            return 'Dort gab es kein Trümmerfeld.';
        }
        $salvagers = $fleet->getShipCount(ShipType::Salvager) * ShipType::Salvager->spec()->cargo;
        $free = min($salvagers, max(0, $this->capacity($fleet) - $fleet->getCargo()->total()));
        $taken = $field->collect($free);
        $fleet->setCargo($fleet->getCargo()->add($taken));
        if ($field->isEmpty()) {
            $this->em->remove($field);
        }

        return \sprintf('Geborgen: %s Eisen, %s Titan.', number_format($taken->iron, 0, ',', '.'), number_format($taken->titanium, 0, ',', '.'));
    }

    private function fleetBattle(Fleet $hunter, Fleet $prey, bool $raid): void
    {
        [$g, $s, $p] = [$hunter->getTargetGalaxy(), $hunter->getTargetSystem(), $hunter->getTargetPosition()];

        $battle = new Battle();
        if ($raid) {
            $battle->spareTransportsWithoutBomber();
        }
        $battle->add(BattleGroup::ATTACKER, $hunter->getOwner(), $hunter->getShips(), 'attacker');
        $battle->add(BattleGroup::DEFENDER, $prey->getOwner(), $prey->getShips(), 'prey');
        $result = $battle->fight();

        $hunter->replaceShips($result->survivors(BattleGroup::ATTACKER, 'attacker'));
        $prey->replaceShips($result->survivors(BattleGroup::DEFENDER, 'prey'));
        $outcome = [$this->leaveDebris($g, $s, $p, $result)];

        if ($raid && $result->transportsSpared) {
            $outcome[] = 'Ohne Bomber blieben die Transportschiffe samt Ladung verschont. Sie kehren zu ihrem Abflugort zurück.';
            if ($prey->hasShips() && !$prey->isReturning()) {
                $prey->recall(\DateTimeImmutable::createFromInterface($this->clock->now()));
            }
        } elseif ($raid && $result->attackerWon() && $hunter->hasShips()) {
            $free = max(0, $this->capacity($hunter) - $hunter->getCargo()->total());
            $cargo = $prey->getCargo();
            $share = $cargo->total() > 0 ? min(1.0, $free / $cargo->total()) : 0;
            $loot = $cargo->multiply($share)->floor();
            $hunter->setCargo($hunter->getCargo()->add($loot));
            $prey->setCargo($cargo->subtract($loot));
            $outcome[] = 'Erbeutet: '.$this->describe($loot).'.';
        }

        if (!$prey->hasShips()) {
            $this->fleets->forget($prey);
            $outcome[] = 'Die überfallene Flotte wurde vernichtet.';
        }

        $what = $raid ? 'Überfall' : 'Abfangkampf';
        $subject = \sprintf('Kampfbericht %s bei [%d:%d:%d]', $what, $g, $s, $p);
        $headline = $result->attackerWon() ? \sprintf('%s erfolgreich.', $what) : \sprintf('%s ohne Entscheidung.', $what);
        $body = implode("\n", [$headline, '', $result->report(), '', ...array_filter($outcome)]);
        $this->notify([$hunter->getOwner(), $prey->getOwner()], $subject, $body);
    }

    /**
     * After a won attack: plunder, bombers, invasion.
     *
     * @return list<string> report lines
     */
    private function plunder(Fleet $attacker, Planet $target, BattleResult $result, \DateTimeImmutable $now): array
    {
        $lines = [];

        // Everything above the storage protection, as far as the cargo space allows.
        $protected = $this->economy->protectedAmount($target);
        $available = Resources::fromCallback(static fn (ResourceType $t): float => max(0, floor($target->getResources()->get($t) - $protected)));
        $free = max(0, $this->capacity($attacker) - $attacker->getCargo()->total());
        $share = $available->total() > 0 ? min(1.0, $free / $available->total()) : 0;
        $loot = $available->multiply($share)->floor();
        $target->setResources($target->getResources()->subtract($loot));
        $attacker->setCargo($attacker->getCargo()->add($loot));
        $lines[] = 'Beute: '.$this->describe($loot).'.';

        // Heavy bombers kill inhabitants outside the bunkers.
        $bombers = $result->survivingCount(BattleGroup::ATTACKER, ShipType::HeavyBomber);
        if ($bombers > 0) {
            $exposed = max(0, $target->getPopulation() - $this->economy->bunkerCapacity($target));
            $killed = min($exposed, $bombers * self::BOMBER_KILLS);
            $target->setPopulation($target->getPopulation() - $killed);
            $lines[] = \sprintf('Bombardement: %s Einwohner getötet, %s in Bunkern geschützt.', number_format($killed, 0, ',', '.'), number_format(min($target->getPopulation(), $this->economy->bunkerCapacity($target)), 0, ',', '.'));
        }

        // Invasion: the troops must outnumber the remaining population.
        $invaders = $attacker->getShipCount(ShipType::InvasionShip);
        if ($invaders > 0) {
            $troops = $invaders * ShipType::InvasionShip->spec()->crew;
            if ($troops > $target->getPopulation()) {
                $old = $target->getOwner()->getUsername();
                $attacker->removeShips(ShipType::InvasionShip, $invaders);
                $target->conquer($attacker->getOwner(), $now);
                $target->setPopulation($troops - floor($target->getPopulation()));
                $lines[] = \sprintf('Invasion gelungen: %s Truppen haben den Planeten von %s übernommen. Er geht nach 4 Wochen vollständig in den Besitz von %s über.', number_format($troops, 0, ',', '.'), $old, $attacker->getOwner()->getUsername());
            } else {
                $lines[] = \sprintf('Invasion gescheitert: %s Truppen gegen %s Einwohner.', number_format($troops, 0, ',', '.'), number_format($target->getPopulation(), 0, ',', '.'));
            }
        }

        return $lines;
    }

    private function leaveDebris(int $g, int $s, int $p, BattleResult $result): ?string
    {
        $wreckage = $result->destroyedValue()->multiply(DebrisField::SHARE)->floor();
        if ($wreckage->total() < 1) {
            return null;
        }
        $field = $this->debris->findAt($g, $s, $p);
        if (null === $field) {
            $field = new DebrisField($g, $s, $p);
            $this->em->persist($field);
        }
        $field->add($wreckage);

        return \sprintf('Trümmerfeld: %s Eisen, %s Titan treiben im Orbit.', number_format($wreckage->iron, 0, ',', '.'), number_format($wreckage->titanium, 0, ',', '.'));
    }

    private function capacity(Fleet $fleet): float
    {
        $capacity = 0;
        foreach ($fleet->getShipList() as $entry) {
            $capacity += $entry['type']->spec()->cargo * $entry['count'];
        }

        return $capacity;
    }

    private function describe(Resources $resources): string
    {
        $parts = [];
        foreach (ResourceType::cases() as $type) {
            if ($resources->get($type) >= 1) {
                $parts[] = $type->label().' '.number_format($resources->get($type), 0, ',', '.');
            }
        }

        return [] === $parts ? 'nichts' : implode(', ', $parts);
    }

    /**
     * Sends the same report once to each player involved.
     *
     * @param list<User> $recipients
     */
    private function notify(array $recipients, string $subject, string $body): void
    {
        $sent = [];
        foreach ($recipients as $recipient) {
            $key = $recipient->getId() ?? spl_object_id($recipient);
            if (!isset($sent[$key])) {
                $this->inbox->send($recipient, $subject, $body);
                $sent[$key] = true;
            }
        }
    }
}
