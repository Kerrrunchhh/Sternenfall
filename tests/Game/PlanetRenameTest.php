<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\Economy;
use App\Game\GameException;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class PlanetRenameTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
    }

    public function testOwnerRenamesAndSpacesAreTidied(): void
    {
        $owner = new User('besitzer', $this->now);
        $planet = new Planet($owner, 'Alt', 1, 1, 1, $this->now);

        $this->service()->rename($planet, $owner, "  Neu   Terra \n");

        self::assertSame('Neu Terra', $planet->getName());
    }

    public function testInvaderMayRenameDuringTheIntegrationPeriod(): void
    {
        $planet = new Planet(new User('opfer', $this->now), 'Beute', 1, 1, 1, $this->now);
        $invader = new User('eroberer', $this->now);
        $planet->conquer($invader, $this->now);
        self::assertTrue($planet->isIntegrating($this->now));

        $this->service()->rename($planet, $invader, 'Erobert');

        self::assertSame('Erobert', $planet->getName());
    }

    public function testForeignPlanetsCanNotBeRenamed(): void
    {
        $planet = new Planet(new User('besitzer', $this->now), 'Fremd', 1, 1, 1, $this->now);

        $this->expectException(GameException::class);
        $this->service()->rename($planet, new User('nachbar', $this->now), 'Meins');
    }

    public function testNameMustHaveOneToThirtyCharacters(): void
    {
        $owner = new User('besitzer', $this->now);
        $planet = new Planet($owner, 'Alt', 1, 1, 1, $this->now);

        foreach (['   ', str_repeat('x', 31)] as $invalid) {
            try {
                $this->service()->rename($planet, $owner, $invalid);
                self::fail("'$invalid' should be rejected");
            } catch (GameException) {
                self::assertSame('Alt', $planet->getName());
            }
        }
    }

    private function service(): PlanetService
    {
        $economy = new Economy(1.0);

        return new PlanetService($this->createStub(EntityManagerInterface::class), new LockFactory(new InMemoryStore()), new MockClock($this->now), new PlanetUpdater($economy), $economy);
    }
}
