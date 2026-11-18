<?php

namespace App\Tests\Game;

use App\Entity\Message;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\Economy;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\LifeForm;
use App\Game\LifeFormService;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Repository\TradePostRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Admins correct a player's life form: no hero point rules, no cooldown, his own 30 days untouched. */
final class AdminLifeFormTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testAnAdminChangesTheLifeFormWithoutTheRules(): void
    {
        $now = new \DateTimeImmutable('2026-11-18 12:00');
        $player = new User('spieler', $now->modify('-2 days'));
        $player->changeLifeForm(LifeForm::Settler, $now->modify('-2 days'));
        $planet = new Planet($player, 'Heim', 1, 2, 3, $now);
        $admin = new User('admin', $now);
        $service = $this->service($now);

        try {
            $service->change($planet, LifeForm::Pirate);
            self::fail('the player himself may not: not criminal and within 30 days');
        } catch (GameException) {
            $this->addToAssertionCount(1);
        }

        $service->change($planet, LifeForm::Pirate, $admin);

        self::assertSame(LifeForm::Pirate, $player->getLifeForm());
        self::assertEquals($now->modify('-2 days'), $player->getLifeFormSince(), 'his own cooldown is not restarted');
        $message = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Message))[0];
        self::assertSame($player, $message->getRecipient());
    }

    private function service(\DateTimeImmutable $now): LifeFormService
    {
        $clock = new MockClock($now);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void { $this->persisted[] = $o; });
        $posts = $this->createStub(TradePostRepository::class);
        $posts->method('findBy')->willReturn([]);
        $economy = new Economy(1.0);
        $locks = new LockFactory(new InMemoryStore());

        return new LifeFormService(new PlanetService($em, $locks, $clock, new PlanetUpdater($economy), $economy), $posts, $em, new Inbox($em, $clock), $locks);
    }
}
