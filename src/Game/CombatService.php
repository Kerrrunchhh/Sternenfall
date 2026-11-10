<?php

namespace App\Game;

use App\Entity\BattleReport;
use App\Entity\DebrisField;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\User;
use App\Repository\DebrisFieldRepository;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Random\Randomizer;

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
        private Randomizer $random = new Randomizer(),
        private ?BattleReports $reports = null,
        private ?Espionage $espionage = null,
        private ?Diplomacy $diplomacy = null,
        private ?GameMailer $gameMailer = null,
    ) {
    }

    /**
     * An attack on a planet. Fleets that joined the attack (a joint attack of several
     * players) fight together with the leading fleet and share the loot by free cargo space;
     * only the leading fleet's invasion ships can take the planet.
     *
     * @param list<Fleet> $joiners
     */
    public function attackPlanet(Fleet $attacker, Planet $target, array $joiners = []): void
    {
        $this->planets->transaction($target, function (Planet $target, \DateTimeImmutable $now) use ($attacker, $joiners): void {
            $attackers = [$attacker, ...$joiners];
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
            foreach ($attackers as $i => $fleet) {
                $battle->add(BattleGroup::ATTACKER, $fleet->getOwner(), $fleet->getShips(), 0 === $i ? 'attacker' : 'joiner:'.$i);
            }
            $battle->add(BattleGroup::DEFENDER, $defender, $target->getShips(), 'hangar');
            foreach ($guards as $guard) {
                $battle->add(BattleGroup::DEFENDER, $guard->getOwner(), $guard->getShips(), 'fleet:'.$guard->getId());
            }
            $result = $battle->fight();
            $experience = $this->awardExperience($result);

            foreach ($attackers as $i => $fleet) {
                $fleet->replaceShips($result->survivors(BattleGroup::ATTACKER, 0 === $i ? 'attacker' : 'joiner:'.$i));
            }
            $target->replaceShips($result->survivors(BattleGroup::DEFENDER, 'hangar'));
            foreach ($guards as $guard) {
                $guard->replaceShips($result->survivors(BattleGroup::DEFENDER, 'fleet:'.$guard->getId()));
                if (!$guard->hasShips()) {
                    $this->fleets->forget($guard);
                }
            }
            $outcome = [$this->leaveDebris($g, $s, $p, $result)];
            $this->showDebris($result->participants(), $g, $s, $p);

            if ($result->attackerWon()) {
                $outcome = [...$outcome, ...$this->plunder($attackers, $target, $result, $now)];
                $headline = [] === $joiners ? 'Der Angreifer hat gewonnen.' : 'Der Verband hat gewonnen.';
            } else {
                $alive = [] !== array_filter($attackers, static fn (Fleet $f): bool => $f->hasShips());
                $headline = $alive ? 'Unentschieden: die Verteidigung hält.' : ([] === $joiners ? 'Der Angreifer wurde vernichtet.' : 'Der Verband wurde vernichtet.');
            }
            if ([] !== $joiners) {
                $headline .= \sprintf(' Verbandsangriff mit %d Flotten (%s).', \count($attackers), implode(', ', array_unique(array_map(static fn (Fleet $f): string => $f->getOwner()->getUsername(), $attackers))));
            }

            // A fusion loses its bottom planet when the surviving attackers hit harder than its shield.
            $population = $target->getPopulation();
            $brokenOff = null;
            if ($target->isFused()) {
                $power = $result->attackPower(BattleGroup::ATTACKER);
                $shield = $this->economy->planetShield($target);
                if ($power > $shield) {
                    $brokenOff = Fusion::breakOff($target, $now);
                    $this->em->persist($brokenOff);
                    $outcome[] = \sprintf('Schild durchbrochen (Angriffskraft %s gegen Schild %s): %s [%s] wurde von der Fusion abgesprengt und ist wieder ein eigener Planet. Der Schild ist danach wieder voll.', number_format($power, 0, ',', '.'), number_format($shield, 0, ',', '.'), $brokenOff->getName(), $brokenOff->getCoordinates());
                } else {
                    $outcome[] = \sprintf('Der Schild der Fusion hält (Angriffskraft %s gegen Schild %s).', number_format($power, 0, ',', '.'), number_format($shield, 0, ',', '.'));
                }
            }

            if ($result->attackerWon()) {
                $outcome = [...$outcome, ...$this->invade($attackers, $target, $brokenOff, $population, $now)];
            }

            $subject = \sprintf('Kampfbericht %s [%s]', $target->getName(), $target->getCoordinates());
            $body = static fn (?User $viewer): string => implode("\n", [$headline, '', $result->report($viewer), '', ...array_filter($outcome), ...$experience]);
            $this->notify([...array_map(static fn (Fleet $f): User => $f->getOwner(), $attackers), $defender, ...array_map(static fn (Fleet $f): User => $f->getOwner(), $guards), ...$result->participants()], $subject, $body);
        });
    }

    /** A Wächter or Pirat fleet meets an attacking fleet just before it arrives. */
    /** @param list<Fleet> $companions fleets flying together with the prey (joint attack) */
    public function intercept(Fleet $hunter, Fleet $prey, array $companions = []): void
    {
        $this->fleetBattle($hunter, [$prey, ...$companions], raid: false);
    }

    /** A Pirat fleet raids a fleet transporting resources. */
    /** @param list<Fleet> $companions fleets flying together with the prey (convoy with escorts) */
    public function raid(Fleet $hunter, Fleet $prey, array $companions = []): void
    {
        $this->fleetBattle($hunter, [$prey, ...$companions], raid: true);
    }

    /** Salvagers collect a debris field. */
    public function salvage(Fleet $fleet): string
    {
        $field = $this->debris->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition());
        if (null === $field) {
            return 'Dort gab es kein Trümmerfeld.';
        }
        // Debris goes into the salvagers' hold (plus free freighter space beside them).
        $salvagers = $fleet->getShipCount(ShipType::Salvager) * ShipType::Salvager->salvageCapacity();
        $free = min($salvagers, max(0, $salvagers + $this->capacity($fleet) - $fleet->getCargo()->total()));
        $taken = $field->collect($free);
        $fleet->setCargo($fleet->getCargo()->add($taken));
        if ($field->isEmpty()) {
            $this->em->remove($field);
        }
        // The salvagers saw what is left.
        $this->espionage?->noteDebris($fleet->getOwner(), $field->getGalaxy(), $field->getSystem(), $field->getPosition());

        return \sprintf('Geborgen: %s Eisen, %s Titan.', number_format($taken->iron, 0, ',', '.'), number_format($taken->titanium, 0, ',', '.'));
    }

    /** @param list<Fleet> $preys the attacked fleet and those flying with it */
    private function fleetBattle(Fleet $hunter, array $preys, bool $raid): void
    {
        [$g, $s, $p] = [$hunter->getTargetGalaxy(), $hunter->getTargetSystem(), $hunter->getTargetPosition()];

        $battle = new Battle();
        if ($raid) {
            $battle->spareTransportsWithoutBomber();
        }
        $battle->add(BattleGroup::ATTACKER, $hunter->getOwner(), $hunter->getShips(), 'attacker');
        foreach ($preys as $i => $prey) {
            $battle->add(BattleGroup::DEFENDER, $prey->getOwner(), $prey->getShips(), 'prey:'.$i);
        }
        $result = $battle->fight();
        $experience = $this->awardExperience($result);

        $hunter->replaceShips($result->survivors(BattleGroup::ATTACKER, 'attacker'));
        foreach ($preys as $i => $prey) {
            $prey->replaceShips($result->survivors(BattleGroup::DEFENDER, 'prey:'.$i));
        }
        $outcome = [$this->leaveDebris($g, $s, $p, $result)];
        $this->showDebris($result->participants(), $g, $s, $p);
        if (\count($preys) > 1) {
            $outcome[] = \sprintf('%s flogen gemeinsam: %s.', $raid ? 'Im Konvoi' : 'Im Verband', implode(', ', array_map(static fn (Fleet $f): string => $f->getLabel().' ('.$f->getOwner()->getUsername().')', $preys)));
        }

        $cargo = array_reduce($preys, static fn (Resources $sum, Fleet $f): Resources => $sum->add($f->getCargo()), new Resources());
        if ($raid && $result->transportsSpared) {
            $outcome[] = 'Ohne Bomber blieben die Transportschiffe samt Ladung verschont. Sie kehren zu ihrem Abflugort zurück.';
            foreach ($preys as $prey) {
                if ($prey->hasShips() && !$prey->isReturning()) {
                    $prey->recall(\DateTimeImmutable::createFromInterface($this->clock->now()));
                }
            }
        } elseif ($raid && $result->attackerWon() && $cargo->total() < 1) {
            $outcome[] = 'Die Flotte war leer – keine Beute.';
        } elseif ($raid && $result->attackerWon() && $hunter->hasShips()) {
            $free = max(0, $this->lootCapacity($hunter) - $hunter->getCargo()->total());
            $share = $cargo->total() > 0 ? min(1.0, $free / $cargo->total()) : 0;
            $loot = new Resources();
            foreach ($preys as $prey) {
                $part = $prey->getCargo()->multiply($share)->floor();
                $prey->setCargo($prey->getCargo()->subtract($part));
                $loot = $loot->add($part);
            }
            $hunter->setCargo($hunter->getCargo()->add($loot));
            $outcome[] = 'Erbeutet: '.$this->describe($loot).'.';
        }

        foreach ($preys as $prey) {
            if (!$prey->hasShips()) {
                $this->fleets->forget($prey);
                $outcome[] = \sprintf('%s wurde vernichtet.', $prey->getLabel());
            }
        }

        $what = $raid ? 'Überfall' : 'Abfangkampf';
        $subject = \sprintf('Kampfbericht %s bei [%d:%d:%d]', $what, $g, $s, $p);
        $headline = $result->attackerWon() ? \sprintf('%s erfolgreich.', $what) : \sprintf('%s ohne Entscheidung.', $what);
        $body = static fn (?User $viewer): string => implode("\n", [$headline, '', $result->report($viewer), '', ...array_filter($outcome), ...$experience]);
        $this->notify([$hunter->getOwner(), ...array_map(static fn (Fleet $f): User => $f->getOwner(), $preys), ...$result->participants()], $subject, $body);
    }

    /**
     * After a won attack: plunder, bombers, invasion.
     *
     * @return list<string> report lines
     */
    /** @param list<Fleet> $attackers */
    private function plunder(array $attackers, Planet $target, BattleResult $result, \DateTimeImmutable $now): array
    {
        $lines = [];

        // Everything above the storage protection, as far as the cargo space allows –
        // in a joint attack shared by the free cargo space of each fleet.
        $protected = $this->economy->protectedAmount($target);
        $available = Resources::fromCallback(static fn (ResourceType $t): float => max(0, floor($target->getResources()->get($t) - $protected)));
        $space = [];
        foreach ($attackers as $i => $fleet) {
            $space[$i] = $fleet->hasShips() ? max(0, $this->lootCapacity($fleet) - $fleet->getCargo()->total()) : 0;
        }
        $free = array_sum($space);
        $share = $available->total() > 0 ? min(1.0, $free / $available->total()) : 0;
        $loot = $available->multiply($share)->floor();
        $taken = new Resources();
        foreach ($attackers as $i => $fleet) {
            if ($space[$i] <= 0) {
                continue;
            }
            $part = $loot->multiply($space[$i] / $free)->floor();
            $fleet->setCargo($fleet->getCargo()->add($part));
            $taken = $taken->add($part);
            if (\count($attackers) > 1) {
                $lines[] = \sprintf('Beute von %s: %s.', $fleet->getOwner()->getUsername(), $this->describe($part));
            }
        }
        $target->setResources($target->getResources()->subtract($taken));
        if (1 === \count($attackers)) {
            $lines[] = 'Beute: '.$this->describe($taken).'.';
        }

        // Heavy bombers kill inhabitants outside the bunkers.
        $bombers = $result->survivingCount(BattleGroup::ATTACKER, ShipType::HeavyBomber);
        if ($bombers > 0) {
            $exposed = max(0, $target->getPopulation() - $this->economy->bunkerCapacity($target));
            $killed = min($exposed, $bombers * self::BOMBER_KILLS);
            $target->setPopulation($target->getPopulation() - $killed);
            $lines[] = \sprintf('Bombardement: %s Einwohner getötet, %s in Bunkern geschützt.', number_format($killed, 0, ',', '.'), number_format(min($target->getPopulation(), $this->economy->bunkerCapacity($target)), 0, ',', '.'));
        }

        return $lines;
    }

    /**
     * Invasion after a won attack. The chance depends on the invasion ships and the
     * population of the planet or the whole fusion (see Economy::invasionChance). A fusion
     * loses only one planet: the one just broken off by the attack, otherwise its bottom planet.
     *
     * @return list<string> report lines
     */
    /**
     * @param list<Fleet> $attackers all attacking fleets (a joint attack has several)
     *
     * @return list<string> report lines
     */
    private function invade(array $attackers, Planet $target, ?Planet $brokenOff, float $population, \DateTimeImmutable $now): array
    {
        // The invasion ships of all fleets count together.
        $byPlayer = [];
        foreach ($attackers as $fleet) {
            $count = $fleet->getShipCount(ShipType::InvasionShip);
            if ($count > 0) {
                $id = (int) $fleet->getOwner()->getId();
                $byPlayer[$id] = ['user' => $fleet->getOwner(), 'ships' => ($byPlayer[$id]['ships'] ?? 0) + $count];
            }
        }
        $invaders = array_sum(array_column($byPlayer, 'ships'));
        if (0 === $invaders) {
            return [];
        }
        // Home planets can't be taken: only then the invasion ships come back.
        if (null === $brokenOff && !$target->isFused() && $target->isHomeworld()) {
            return [\sprintf('Invasion fehlgeschlagen: %s [%s] ist der Heimatplanet von %s. Heimatplaneten können nicht erobert werden. Die Invasionsschiffe kehren zurück.', $target->getName(), $target->getCoordinates(), $target->getOwner()->getUsername())];
        }

        // Every other attempt costs the invasion ships, whatever happens.
        foreach ($attackers as $fleet) {
            $fleet->removeShips(ShipType::InvasionShip, $fleet->getShipCount(ShipType::InvasionShip));
        }

        // Who may take the planet: an invading player with enough free slots (3, in a war 2); several – chance decides.
        $candidates = [];
        $refusals = [];
        foreach ($byPlayer as ['user' => $user]) {
            $inWar = $this->diplomacy?->atWar($user, $target->getOwner()) ?? false;
            $cost = Planet::invadedSlots($now, $inWar, $now);
            $slots = $this->economy->colonySlots($user);
            $owned = Economy::ownedSlots($user, $now);
            if ($owned + $cost <= $slots) {
                $candidates[] = ['user' => $user, 'war' => $inWar];
            } else {
                $refusals[] = \sprintf('%s hat nur noch %d von %d nötigen Planetenplätzen frei', $user->getUsername(), max(0, $slots - $owned), $cost);
            }
        }
        if ([] === $candidates) {
            return [\sprintf('Invasion fehlgeschlagen: Ein invadierter Planet belegt 3 Planetenplätze (im Krieg 2) – %s. Die Invasionsschiffe sind verloren.', implode('; ', $refusals))];
        }
        ['user' => $conqueror, 'war' => $inWar] = $candidates[\count($candidates) > 1 ? $this->random->getInt(0, \count($candidates) - 1) : 0];

        $chance = Economy::invasionChance($invaders, $population);
        $odds = \sprintf('%d Invasionsschiff(e) gegen %s Einwohner, Chance %s %%', $invaders, number_format($population, 0, ',', '.'), number_format(100 * $chance, 0, ',', '.'));
        if ($this->random->nextFloat() >= $chance) {
            return [\sprintf('Invasion gescheitert (%s). Die Invasionsschiffe sind verloren.', $odds)];
        }

        $defender = $target->getOwner();
        $victim = $brokenOff;
        if (null === $victim && $target->isFused()) {
            $victim = Fusion::breakOff($target, $now);
            $this->em->persist($victim);
        }
        $victim ??= $target;

        $victim->conquer($conqueror, $now, $inWar);
        $victim->setPopulation($victim->getPopulation() + $invaders * ShipType::InvasionShip->spec()->crew);
        $slotsNote = $inWar
            ? 'Im Krieg belegt er 2 Planetenplätze, nach 30 Tagen geht er vollständig in seinen Besitz über.'
            : 'Er belegt 3 Planetenplätze, nach 14 Tagen 2, und geht nach 30 Tagen vollständig in seinen Besitz über.';
        $who = \count($candidates) > 1 ? \sprintf('%s (ausgelost unter %s)', $conqueror->getUsername(), implode(', ', array_map(static fn (array $c): string => $c['user']->getUsername(), $candidates))) : $conqueror->getUsername();
        $lines = [\sprintf('Invasion gelungen (%s): %s [%s] gehört jetzt %s. %s', $odds, $victim->getName(), $victim->getCoordinates(), $who, $slotsNote)];
        if ($victim !== $target) {
            $lines[0] = \sprintf('Invasion gelungen (%s): %s [%s] wurde von der Fusion abgesprengt und gehört jetzt %s. %s', $odds, $victim->getName(), $victim->getCoordinates(), $who, $slotsNote);
        }

        // Research runs on the (top) planet it was started on and ends only when that planet is lost.
        if ($defender->isResearching() && $defender->getResearchPlanet() === $victim) {
            $lines[] = \sprintf('Die Forschung an %s von %s ist mit dem Planeten verloren.', $defender->getResearchType()->label(), $defender->getUsername());
            $defender->clearResearch();
        }

        return $lines;
    }

    /**
     * Everybody who fought at a place sees the debris field there.
     *
     * @param list<User> $players
     */
    private function showDebris(array $players, int $g, int $s, int $p): void
    {
        foreach ($players as $player) {
            $this->espionage?->noteDebris($player, $g, $s, $p);
        }
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

    /** Cargo space for plunder: only trade ships carry it. */
    /** Warfleets carry their loot in salvage ships – freighters don't fly with them. */
    private function lootCapacity(Fleet $fleet): float
    {
        return $fleet->getShipCount(ShipType::Salvager) * ShipType::Salvager->salvageCapacity();
    }

    private function capacity(Fleet $fleet): float
    {
        $capacity = 0;
        foreach ($fleet->getShipList() as $entry) {
            $capacity += $entry['type']->cargo() * $entry['count'];
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
     * Credits the combat experience of a battle and describes it for the report.
     *
     * @return list<string> report lines
     */
    private function awardExperience(BattleResult $result): array
    {
        $lines = [];
        foreach ($result->experience() as ['user' => $user, 'experience' => $experience]) {
            $user->addCombatExperience($experience);
            if ($experience > 0) {
                $this->em->persist(new \App\Entity\ExperienceGain($user, $experience, \DateTimeImmutable::createFromInterface($this->clock->now())));
            }
            if ($experience >= 0.5) {
                $lines[] = \sprintf('  %s +%s', $user->getUsername(), number_format($experience, 0, ',', '.'));
            }
        }

        return [] === $lines ? [] : ['', 'Kampferfahrung (zerstörte Schildpunkte des Gegners):', ...$lines];
    }

    /**
     * Sends the report once to each player involved, each from his own point of view.
     *
     * @param list<User>                $recipients
     * @param \Closure(?User): string   $body       the report as seen by a recipient (null: neutral)
     */
    private function notify(array $recipients, string $subject, \Closure $body): void
    {
        // One neutral, shareable copy of the report, linked at the end of every copy.
        $share = '';
        if (null !== $this->reports) {
            $link = $this->reports->publish($subject, $body(null));
            $share = \sprintf("\n\nKampfbericht teilen (%d Tage gültig, ohne Anmeldung lesbar): %s", BattleReport::DAYS_AVAILABLE, $link);
        }
        // Every attacker and defender gets the report from his own point of view.
        $sent = [];
        foreach ($recipients as $recipient) {
            $key = $recipient->getId() ?? spl_object_id($recipient);
            if (!isset($sent[$key])) {
                $this->inbox->send($recipient, $subject, $body($recipient).$share);
                $this->gameMailer?->notify($recipient, 'battle', $subject, $body($recipient).$share);
                $sent[$key] = true;
            }
        }
    }
}
