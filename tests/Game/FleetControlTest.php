<?php

namespace App\Tests\Game;

use App\Controller\AbstractGameController;
use App\Controller\Attribute\RequiresFleetControl;
use App\Entity\Planet;
use App\Entity\User;
use App\EventListener\FleetControlListener;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Fleet control centers: their levels on all planets add up to the fleets one may command; commands need one on the selected planet. */
final class FleetControlTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-12 10:00:00');
    }

    public function testCapacityIsTheSumOfAllLevels(): void
    {
        $player = new User('admiral', $this->now);
        $home = new Planet($player, 'Heim', 1, 1, 1, $this->now);
        $colony = new Planet($player, 'Kolonie', 1, 2, 1, $this->now);
        self::assertSame(0, Economy::fleetCapacity($player), 'nobody gets one for free');

        $home->setBuildingLevel(BuildingType::FleetControl, 3);
        $colony->setBuildingLevel(BuildingType::FleetControl, 2);
        self::assertSame(5, Economy::fleetCapacity($player));
        self::assertSame([], array_filter(BuildingType::FleetControl->requirements(), static fn ($r): bool => BuildingType::Shipyard !== $r->subject), 'needs only a shipyard');
    }

    public function testCommandsAreRefusedWithoutAControlCenterOnTheSelectedPlanet(): void
    {
        $player = new User('admiral', $this->now);
        $home = new Planet($player, 'Heim', 1, 1, 1, $this->now);
        $colony = new Planet($player, 'Kolonie', 1, 2, 1, $this->now);
        (new \ReflectionProperty(Planet::class, 'id'))->setValue($home, 1);
        (new \ReflectionProperty(Planet::class, 'id'))->setValue($colony, 2);
        $home->setBuildingLevel(BuildingType::FleetControl, 1);

        // Colony selected: refused, back to the fleet page with a message.
        [$event, $session] = $this->event($colony->getId(), new RequiresFleetControl());
        $this->listener($player)($event);
        $response = ($event->getController())();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/fleet', $response->getTargetUrl());
        self::assertCount(1, $session->getFlashBag()->get('error'));

        // Home selected: the action runs.
        [$event] = $this->event($home->getId(), new RequiresFleetControl());
        $this->listener($player)($event);
        self::assertSame('action', ($event->getController())());

        // Actions without the attribute (e.g. probes from the galaxy) are never stopped.
        [$event] = $this->event($colony->getId(), null);
        $this->listener($player)($event);
        self::assertSame('action', ($event->getController())());
    }

    /** @return array{ControllerArgumentsEvent, Session} */
    private function event(?int $selected, ?RequiresFleetControl $attribute): array
    {
        $session = new Session(new MockArraySessionStorage());
        $session->set(AbstractGameController::SESSION_PLANET, $selected);
        $request = new Request();
        $request->setSession($session);
        $controller = [new FleetControlTestController(), null === $attribute ? 'free' : 'command'];
        $kernel = $this->createStub(HttpKernelInterface::class);

        return [new ControllerArgumentsEvent($kernel, $controller, [], $request, HttpKernelInterface::MAIN_REQUEST), $session];
    }

    private function listener(User $player): FleetControlListener
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($player);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route): string => 'app_fleet' === $route ? '/fleet' : '/'.$route);
        $economy = new Economy(1.0);
        $clock = new MockClock($this->now);
        $planets = new PlanetService($this->createStub(EntityManagerInterface::class), new LockFactory(new InMemoryStore()), $clock, new PlanetUpdater($economy), $economy);

        return new FleetControlListener($security, $planets, $urls);
    }
}

/** Stands in for a controller: one fleet command, one free action. */
final class FleetControlTestController
{
    #[RequiresFleetControl]
    public function command(): string
    {
        return 'action';
    }

    public function free(): string
    {
        return 'action';
    }
}
