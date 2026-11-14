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
use App\I18n\Num;
use App\I18n\Goods;
use App\I18n\Lines;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

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
        private ?\App\Repository\PlanetRepository $planetRepository = null,
        private ?ContractService $contracts = null,
        private ?BountyService $bounties = null,
        private ?AccountDeletion $deletion = null,
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

            $beforeBombing = $target->getPopulation();
            if ($result->attackerWon()) {
                $outcome = [...$outcome, ...$this->plunder($attackers, $target, $result, $now)];
                $headline = [] === $joiners ? t('Der Angreifer hat gewonnen.') : t('Der Verband hat gewonnen.');
            } else {
                $alive = [] !== array_filter($attackers, static fn (Fleet $f): bool => $f->hasShips());
                $headline = $alive ? t('Unentschieden: die Verteidigung hält.') : ([] === $joiners ? t('Der Angreifer wurde vernichtet.') : t('Der Verband wurde vernichtet.'));
            }
            if ([] !== $joiners) {
                $headline = new Lines([$headline, t('Verbandsangriff mit {count} Flotten ({value}).', ['{count}' => \count($attackers), '{value}' => implode(', ', array_unique(array_map(static fn (Fleet $f): string => $f->getOwner()->getUsername(), $attackers)))])], ' ');
            }

            // Bombed civilians and destroyed crews: hero or crime points for the attackers.
            $outcome = [...$outcome, ...HeroPoints::apply(HeroPoints::forBattle($result, max(0, $beforeBombing - $target->getPopulation()), $defender))];

            // A fusion loses its bottom planet when the surviving attackers hit harder than its shield.
            $population = $target->getPopulation();
            $brokenOff = null;
            if ($target->isFused()) {
                $power = $result->attackPower(BattleGroup::ATTACKER);
                $shield = $this->economy->planetShield($target);
                if ($power > $shield) {
                    $brokenOff = Fusion::breakOff($target, $now);
                    $this->em->persist($brokenOff);
                    $outcome[] = t('Schild durchbrochen (Angriffskraft {power} gegen Schild {shield}): {name} [{coords}] wurde von der Fusion abgesprengt und ist wieder ein eigener Planet. Der Schild ist danach wieder voll.', ['{power}' => new Num($power), '{shield}' => new Num($shield), '{name}' => $brokenOff->getName(), '{coords}' => $brokenOff->getCoordinates()]);
                } else {
                    $outcome[] = t('Der Schild der Fusion hält (Angriffskraft {power} gegen Schild {shield}).', ['{power}' => new Num($power), '{shield}' => new Num($shield)]);
                }
            }

            if ($result->attackerWon()) {
                $outcome = [...$outcome, ...$this->invade($attackers, $target, $brokenOff, $population, $now)];
            }

            $subject = t('Kampfbericht {name} [{coords}]', ['{name}' => $target->getName(), '{coords}' => $target->getCoordinates()]);
            $body = static fn (?User $viewer): TranslatableInterface => new Lines([$headline, '', $result->report($viewer), '', ...array_filter($outcome), ...$experience]);
            $this->notify([...array_map(static fn (Fleet $f): User => $f->getOwner(), $attackers), $defender, ...array_map(static fn (Fleet $f): User => $f->getOwner(), $guards), ...$result->participants()], $subject, $body, $result);
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
    public function salvage(Fleet $fleet): TranslatableInterface
    {
        $field = $this->debris->findAt($fleet->getTargetGalaxy(), $fleet->getTargetSystem(), $fleet->getTargetPosition());
        if (null === $field) {
            return t('Dort gab es kein Trümmerfeld.');
        }
        // Salvagers never carry anything: what they collect lands straight at their start planet.
        $taken = $field->collect($this->lootCapacity($fleet));
        $where = $this->depositAtStart($fleet, $taken);
        if ($field->isEmpty()) {
            $this->em->remove($field);
        }
        // The salvagers saw what is left.
        $this->espionage?->noteDebris($fleet->getOwner(), $field->getGalaxy(), $field->getSystem(), $field->getPosition());

        return t('Geborgen: {iron} Eisen, {titanium} Titan – {where}.', ['{iron}' => new Num($taken->iron), '{titanium}' => new Num($taken->titanium), '{where}' => $where]);
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
        // Intercepting an attack defends: it can only earn hero points. A raid is an attack.
        $outcome = [...$outcome, ...HeroPoints::apply(HeroPoints::forBattle($result, defensive: !$raid))];
        if (\count($preys) > 1) {
            $outcome[] = t('{value} flogen gemeinsam: {value2}.', ['{value}' => $raid ? t('Im Konvoi') : t('Im Verband'), '{value2}' => new Lines(array_map(static fn (Fleet $f): TranslatableInterface => t('{label} ({player})', ['{label}' => $f->getLabel(), '{player}' => $f->getOwner()->getUsername()]), $preys), ', ')]);
        }

        $cargo = array_reduce($preys, static fn (Resources $sum, Fleet $f): Resources => $sum->add($f->getCargo()), new Resources());
        if ($raid && $result->transportsSpared) {
            $outcome[] = t('Ohne Bomber blieben die Transportschiffe samt Ladung verschont. Sie kehren zu ihrem Abflugort zurück.');
            foreach ($preys as $prey) {
                if ($prey->hasShips() && !$prey->isReturning()) {
                    $prey->recall(\DateTimeImmutable::createFromInterface($this->clock->now()));
                }
            }
        } elseif ($raid && $result->attackerWon() && $cargo->total() < 1) {
            $outcome[] = t('Die Flotte war leer – keine Beute.');
        } elseif ($raid && $result->attackerWon() && $hunter->hasShips()) {
            $free = $this->lootCapacity($hunter);
            $share = $cargo->total() > 0 ? min(1.0, $free / $cargo->total()) : 0;
            $loot = new Resources();
            foreach ($preys as $prey) {
                $part = $prey->getCargo()->multiply($share)->floor();
                $prey->setCargo($prey->getCargo()->subtract($part));
                $loot = $loot->add($part);
            }
            $where = $this->depositAtStart($hunter, $loot);
            $outcome[] = t('Erbeutet: {loot} – {where}.', ['{loot}' => $this->describe($loot), '{where}' => $where]);
        }

        foreach ($preys as $prey) {
            if (!$prey->hasShips()) {
                $this->fleets->forget($prey);
                $outcome[] = t('{label} wurde vernichtet.', ['{label}' => $prey->getLabel()]);
            }
        }

        $coords = \sprintf('%d:%d:%d', $g, $s, $p);
        $subject = $raid ? t('Kampfbericht Überfall bei [{coords}]', ['{coords}' => $coords]) : t('Kampfbericht Abfangkampf bei [{coords}]', ['{coords}' => $coords]);
        $headline = $result->attackerWon()
            ? ($raid ? t('Überfall erfolgreich.') : t('Abfangkampf erfolgreich.'))
            : ($raid ? t('Überfall ohne Entscheidung.') : t('Abfangkampf ohne Entscheidung.'));
        $body = static fn (?User $viewer): TranslatableInterface => new Lines([$headline, '', $result->report($viewer), '', ...array_filter($outcome), ...$experience]);
        $this->notify([$hunter->getOwner(), ...array_map(static fn (Fleet $f): User => $f->getOwner(), $preys), ...$result->participants()], $subject, $body, $result);
    }

    /**
     * After a won attack: plunder, bombers, invasion.
     *
     * @return list<TranslatableInterface> report lines
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
            $space[$i] = $fleet->hasShips() ? $this->lootCapacity($fleet) : 0;
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
            $where = $this->depositAtStart($fleet, $part);
            $taken = $taken->add($part);
            if (\count($attackers) > 1) {
                $lines[] = t('Beute von {player}: {value} – {where}.', ['{player}' => $fleet->getOwner()->getUsername(), '{value}' => $this->describe($part), '{where}' => $where]);
            } else {
                $lines[] = t('Die Bergungsschiffe haben die Beute zum Startplaneten gebracht – {where}.', ['{where}' => $where]);
            }
        }
        $target->setResources($target->getResources()->subtract($taken));
        if (1 === \count($attackers)) {
            $lines[] = t('Beute: {loot}.', ['{loot}' => $this->describe($taken)]);
        }

        // Heavy bombers kill inhabitants outside the bunkers.
        $bombers = $result->survivingCount(BattleGroup::ATTACKER, ShipType::HeavyBomber);
        if ($bombers > 0) {
            $exposed = max(0, $target->getPopulation() - $this->economy->bunkerCapacity($target));
            $killed = min($exposed, $bombers * self::BOMBER_KILLS);
            $target->setPopulation($target->getPopulation() - $killed);
            $lines[] = t('Bombardement: {killed} Einwohner getötet, {value} in Bunkern geschützt.', ['{killed}' => new Num($killed), '{value}' => new Num(min($target->getPopulation(), $this->economy->bunkerCapacity($target)))]);
        }

        return $lines;
    }

    /**
     * Invasion after a won attack. The chance depends on the invasion ships and the
     * population of the planet or the whole fusion (see Economy::invasionChance). A fusion
     * loses only one planet: the one just broken off by the attack, otherwise its bottom planet.
     *
     * @return list<TranslatableInterface> report lines
     */
    /**
     * @param list<Fleet> $attackers all attacking fleets (a joint attack has several)
     *
     * @return list<TranslatableInterface> report lines
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
        // Inactive players lose their protection: even the home planet falls, and at once.
        $defender = $target->getOwner();
        $inactive = $defender->isInactive($now);
        // Home planets can't be taken: only then the invasion ships come back.
        if (null === $brokenOff && !$target->isFused() && $target->isHomeworld() && !$inactive) {
            return [t('Invasion fehlgeschlagen: {name} [{coords}] ist der Heimatplanet von {player}. Heimatplaneten können nicht erobert werden. Die Invasionsschiffe kehren zurück.', ['{name}' => $target->getName(), '{coords}' => $target->getCoordinates(), '{player}' => $target->getOwner()->getUsername()])];
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
            $cost = $inactive ? 1 : Planet::invadedSlots($now, $inWar, $now);
            $slots = $this->economy->colonySlots($user);
            $owned = Economy::ownedSlots($user, $now);
            if ($owned + $cost <= $slots) {
                $candidates[] = ['user' => $user, 'war' => $inWar];
            } else {
                $refusals[] = t('{player} hat nur noch {value} von {cost} nötigen Planetenplätzen frei', ['{player}' => $user->getUsername(), '{value}' => max(0, $slots - $owned), '{cost}' => $cost]);
            }
        }
        if ([] === $candidates) {
            return [$inactive
                ? t('Invasion fehlgeschlagen: Ein Planet eines inaktiven Spielers belegt 1 Planetenplatz – {value}. Die Invasionsschiffe sind verloren.', ['{value}' => new Lines($refusals, '; ')])
                : t('Invasion fehlgeschlagen: Ein invadierter Planet belegt 3 Planetenplätze (im Krieg 2) – {value}. Die Invasionsschiffe sind verloren.', ['{value}' => new Lines($refusals, '; ')])];
        }
        ['user' => $conqueror, 'war' => $inWar] = $candidates[\count($candidates) > 1 ? $this->random->getInt(0, \count($candidates) - 1) : 0];

        $chance = Economy::invasionChance($invaders, $population);
        $odds = t('{invaders} Invasionsschiff(e) gegen {population} Einwohner, Chance {chance} %', ['{invaders}' => $invaders, '{population}' => new Num($population), '{chance}' => new Num(100 * $chance)]);
        if ($this->random->nextFloat() >= $chance) {
            return [t('Invasion gescheitert ({odds}). Die Invasionsschiffe sind verloren.', ['{odds}' => $odds])];
        }

        $victim = $brokenOff;
        if (null === $victim && $target->isFused()) {
            $victim = Fusion::breakOff($target, $now);
            $this->em->persist($victim);
        }
        $victim ??= $target;

        $wasHome = $victim->isHomeworld();
        $victim->conquer($conqueror, $now, $inWar);
        if ($inactive) {
            $victim->markFullyOwned();
            $victim->loseHomeworldStatus();
        }
        $this->contracts?->conquered($victim, $conqueror);
        $victim->setPopulation($victim->getPopulation() + $invaders * ShipType::InvasionShip->spec()->crew);
        $slotsNote = match (true) {
            $inactive => t('{player} war inaktiv: Der Planet belegt 1 Planetenplatz und gehört sofort vollständig dem Eroberer.', ['{player}' => $defender->getUsername()]),
            $inWar => t('Im Krieg belegt er 2 Planetenplätze, nach 30 Tagen geht er vollständig in seinen Besitz über.'),
            default => t('Er belegt 3 Planetenplätze, nach 14 Tagen 2, und geht nach 30 Tagen vollständig in seinen Besitz über.'),
        };
        $who = \count($candidates) > 1 ? t('{player} (ausgelost unter {value})', ['{player}' => $conqueror->getUsername(), '{value}' => implode(', ', array_map(static fn (array $c): string => $c['user']->getUsername(), $candidates))]) : $conqueror->getUsername();
        $lines = [t('Invasion gelungen ({odds}): {name} [{coords}] gehört jetzt {who}. {slotsNote}', ['{odds}' => $odds, '{name}' => $victim->getName(), '{coords}' => $victim->getCoordinates(), '{who}' => $who, '{slotsNote}' => $slotsNote])];
        if ($victim !== $target) {
            $lines[0] = t('Invasion gelungen ({odds}): {name} [{coords}] wurde von der Fusion abgesprengt und gehört jetzt {who}. {slotsNote}', ['{odds}' => $odds, '{name}' => $victim->getName(), '{coords}' => $victim->getCoordinates(), '{who}' => $who, '{slotsNote}' => $slotsNote]);
        }

        // Research runs on the (top) planet it was started on and ends only when that planet is lost.
        if ($defender->isResearchingOn($victim)) {
            $lines[] = t('Die Forschung an {label} von {player} ist mit dem Planeten verloren.', ['{label}' => t($defender->getResearchType()->label()), '{player}' => $defender->getUsername()]);
            $defender->clearResearch();
        }

        // The home planet of an inactive player fell: all his other planets become ownerless.
        if ($inactive && $wasHome && null !== $this->deletion && !$defender->getPlanets()->isEmpty()) {
            $nobody = $this->deletion->nobody();
            foreach ($defender->getPlanets()->toArray() as $planet) {
                if ($defender->isResearchingOn($planet)) {
                    $defender->clearResearch();
                }
                $planet->leaveToNobody($nobody, false);
            }
            $lines[] = t('Das war der Heimatplanet von {player}: Alle übrigen Planeten von {player} sind jetzt herrenlos.', ['{player}' => $defender->getUsername()]);
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

    private function leaveDebris(int $g, int $s, int $p, BattleResult $result): ?TranslatableInterface
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

        return t('Trümmerfeld: {iron} Eisen, {titanium} Titan treiben im Orbit.', ['{iron}' => new Num($wreckage->iron), '{titanium}' => new Num($wreckage->titanium)]);
    }

    /** Cargo space for plunder: only trade ships carry it. */
    /**
     * Salvage ships never carry anything: loot and debris land at once in the storage of the
     * planet the fleet started from – even if it belongs to another player.
     */
    private function depositAtStart(Fleet $fleet, Resources $resources): TranslatableInterface
    {
        if ($resources->total() < 1) {
            return t('nichts erbeutet');
        }
        $start = $this->planetRepository?->findAt($fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());
        if (null === $start) {
            return t('am Startort steht kein Planet, die Rohstoffe sind verloren');
        }
        $start->setResources($start->getResources()->add($resources));

        return t('abgeliefert auf {name} [{coords}]', ['{name}' => $start->getName(), '{coords}' => $start->getCoordinates()]);
    }

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

    private function describe(Resources $resources): TranslatableInterface
    {
        $goods = array_filter($resources->toArray(), static fn (float $amount): bool => $amount >= 1);

        return [] === $goods ? t('nichts') : Goods::summary($goods);
    }

    /**
     * Credits the combat experience of a battle and describes it for the report.
     *
     * @return list<TranslatableInterface> report lines
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
                $lines[] = t('  {player} +{experience}', ['{player}' => $user->getUsername(), '{experience}' => new Num($experience)]);
            }
        }

        $lines = [] === $lines ? [] : ['', t('Kampferfahrung (zerstörte Schildpunkte des Gegners):'), ...$lines];

        // Bounties: destroyed spaceships of a target count for the hunters on the other side.
        return [...$lines, ...($this->bounties?->record($result) ?? [])];
    }

    /**
     * Sends the report once to each player involved, each from his own point of view.
     *
     * @param list<User>                $recipients
     * @param \Closure(?User): TranslatableInterface $body the report as seen by a recipient (null: neutral)
     */
    private function notify(array $recipients, TranslatableInterface $subject, \Closure $body, ?BattleResult $result = null): void
    {
        // One neutral, shareable copy of the report, linked at the end of every copy.
        $share = null;
        if (null !== $this->reports) {
            $link = $this->reports->publish($subject, $body(null), $result?->losses() ?? []);
            $share = t("\nKampfbericht teilen ({value} Tage gültig, ohne Anmeldung lesbar): {link}", ['{value}' => BattleReport::DAYS_AVAILABLE, '{link}' => $link]);
        }
        // Every attacker and defender gets the report from his own point of view.
        $sent = [];
        foreach ($recipients as $recipient) {
            $key = $recipient->getId() ?? spl_object_id($recipient);
            if (!isset($sent[$key])) {
                $text = new Lines([$body($recipient), $share]);
                $this->inbox->send($recipient, $subject, $text);
                $this->gameMailer?->notify($recipient, 'battle', $subject, $text);
                $sent[$key] = true;
            }
        }
    }
}
