<?php

namespace App\Tests\Game;

use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Espionage;
use App\Game\FleetMission;
use App\Game\Radar;
use App\Game\ResearchType;
use App\Game\Universe;
use App\Repository\FleetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class EspionageTest extends TestCase
{
    use RendersMessages;

    private Espionage $espionage;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $intel = $this->createStub(EntityRepository::class);
        $intel->method('findOneBy')->willReturn(null);
        $intel->method('findBy')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($intel);

        $waiting = new Fleet(new User('gast'), 'Versteck', 1, 1, 7, ['pfeil' => 12]);
        $fleets = $this->createStub(FleetRepository::class);
        $fleets->method('findIdleAt')->willReturn([$waiting]);

        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
        $this->espionage = new Espionage($em, $fleets, new MockClock($this->now));
    }

    /** @return iterable<string, array{int, list<string>, list<string>}> */
    public static function levels(): iterable
    {
        // Section headers end with a colon; the hints for higher levels mention the words without one.
        yield 'Stufe 1: nur Typ' => [1, ['Planetentyp', 'Ab Spionagetechnik Stufe 5'], ['Rohstoffe:', 'Gebäude:', 'Stationierte Schiffe und Türme:']];
        yield 'Stufe 5: + Gebäude' => [5, ['Planetentyp', 'Rohstoffe:', 'Gebäude:', 'Kommandozentrale: Stufe 4', 'Ab Spionagetechnik Stufe 10'], ['Stationierte Schiffe und Türme:']];
        yield 'Stufe 10: + Schiffe' => [10, ['Planetentyp', 'Gebäude:', 'Stationierte Schiffe und Türme:', 'Lasergeschütz: 3', 'Flotte von gast', 'Pfeil: 12'], ['Ab Spionagetechnik']];
    }

    /**
     * @param list<string> $contains
     * @param list<string> $absent
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('levels')]
    public function testReportDepthDependsOnEspionageLevel(int $level, array $contains, array $absent): void
    {
        $spy = new User('spion');
        $spy->setResearchLevel(ResearchType::Espionage, $level);
        $planet = new Planet(new User('ziel'), 'Festung', 1, 1, 7, $this->now);
        $planet->setBuildingLevel(BuildingType::CommandCenter, 4);
        $planet->addShips(\App\Game\ShipType::LaserTurret, 3);

        $report = self::text($this->espionage->report($spy, 1, 1, 7, $planet));

        foreach ($contains as $text) {
            self::assertStringContainsString($text, $report);
        }
        foreach ($absent as $text) {
            self::assertStringNotContainsString($text, $report);
        }
    }

    public function testFreeSlotRevealsItsType(): void
    {
        $spy = new User('spion');
        $spy->setResearchLevel(ResearchType::Espionage, 1);

        $report = self::text($this->espionage->report($spy, 2, 50, 9, null));

        self::assertStringContainsString('Freier Platz [2:50:9]', $report);
        self::assertStringContainsString(Universe::planetType(2, 50, 9)->label(), $report);
    }

    public function testOnlyTopEspionageNoticesIncomingProbes(): void
    {
        $owner = new User('besitzer');
        $target = new Planet($owner, 'Heim', 1, 1, 7, $this->now);
        $probe = new Fleet(new User('spion'), 'Sonde', 1, 1, 1, ['scout' => 1]);
        $probe->depart(FleetMission::Spy, 1, 1, 7, $this->now, 60);

        $owner->setResearchLevel(ResearchType::Espionage, 49);
        self::assertFalse($this->espionage->seesProbe($owner, $probe, $target));

        $owner->setResearchLevel(ResearchType::Espionage, 50);
        self::assertTrue($this->espionage->seesProbe($owner, $probe, $target));
        self::assertFalse($this->espionage->seesProbe(new User('dritter'), $probe, $target), 'only the owner of the target');
    }

    public function testTopEspionageNoticesProbesChasingOwnFleets(): void
    {
        $owner = new User('besitzer');
        $owner->setResearchLevel(ResearchType::Espionage, 50);
        $prey = new Fleet($owner, 'Beute', 1, 1, 1, ['freighter' => 1]);
        $probe = new Fleet(new User('spion'), 'Sonde', 1, 1, 1, ['scout' => 1]);
        $probe->depart(FleetMission::Spy, 1, 2, 3, $this->now, 60, $prey);

        self::assertTrue($this->espionage->seesProbe($owner, $probe, null));
        self::assertFalse($this->espionage->seesProbe(new User('dritter'), $probe, null));
    }

    public function testSpiedPlayerLearnsMoreWithHigherEspionage(): void
    {
        $victim = new User('opfer');
        $spy = new User('Kraehe');

        $victim->setResearchLevel(ResearchType::Espionage, 24);
        self::assertNull($this->espionage->spiedNotice($victim, $spy, new \App\I18n\Raw('Dein Planet Heim')));

        $victim->setResearchLevel(ResearchType::Espionage, 25);
        [$subject, $text] = $this->espionage->spiedNotice($victim, $spy, new \App\I18n\Raw('Dein Planet Heim'));
        self::assertSame('Ausspioniert', self::text($subject));
        self::assertStringNotContainsString('Kraehe', self::text($text));

        $victim->setResearchLevel(ResearchType::Espionage, 40);
        [$subject, $text] = $this->espionage->spiedNotice($victim, $spy, new \App\I18n\Raw('Dein Planet Heim'));
        self::assertSame('Ausspioniert von Kraehe', self::text($subject));
        self::assertStringContainsString('von Kraehe', self::text($text));
    }

    public function testHoveringFleetsNeverShowOnRadar(): void
    {
        $fleet = new Fleet(new User('o'), 'Geist', 1, 1, 1, ['freighter' => 1], new \App\Game\Resources(iron: 500));
        $fleet->depart(FleetMission::Hover, 1, 1, 1, $this->now, 600);

        self::assertFalse(Radar::emits($fleet), 'even with cargo on board');
    }

    public function testSpyReportNamesTheDebrisFieldAndRemembersIt(): void
    {
        $field = new \App\Entity\DebrisField(2, 50, 9);
        $field->add(new \App\Game\Resources(iron: 1200, titanium: 800));
        $debris = $this->createStub(\App\Repository\DebrisFieldRepository::class);
        $debris->method('findAt')->willReturn($field);
        $intelRepo = $this->createStub(EntityRepository::class);
        $intelRepo->method('findOneBy')->willReturn(null);
        $persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($intelRepo);
        $em->method('persist')->willReturnCallback(function (object $o) use (&$persisted): void { $persisted[] = $o; });
        $espionage = new Espionage($em, $this->createStub(FleetRepository::class), new MockClock($this->now), $debris);

        $spy = new User('spion');
        $spy->setResearchLevel(ResearchType::Espionage, 1);
        $report = self::text($espionage->report($spy, 2, 50, 9, null));

        self::assertStringContainsString('Trümmerfeld: 1.200 Eisen, 800 Titan', $report);
        $intel = array_values(array_filter($persisted, static fn (object $o): bool => $o instanceof \App\Entity\PlanetIntel));
        self::assertTrue($intel[0]->hasDebris(), 'the spy now knows the field');
        self::assertSame(1200.0, $intel[0]->getDebrisIron());
    }

    public function testCollectedDebrisIsForgotten(): void
    {
        $intel = new \App\Entity\PlanetIntel(new User('x'), 1, 1, 1, Universe::planetType(1, 1, 1), $this->now);
        $intel->seeDebris(500, 100, $this->now);
        self::assertTrue($intel->hasDebris());
        $intel->seeDebris(0, 0, $this->now);
        self::assertFalse($intel->hasDebris());
    }
}
