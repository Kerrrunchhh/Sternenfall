<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\ResourceGift;
use App\Entity\User;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\GameException;
use App\Game\PushingGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/** A smaller player may give a bigger one at most a quarter of his weekly production in 7 days. */
final class PushingGuardTest extends TestCase
{
    use RendersMessages;

    private \DateTimeImmutable $now;
    private User $small;
    private User $big;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-10 12:00');
        $this->small = $this->player('klein', 1, 2);
        $this->big = $this->player('gross', 2, 12);
    }

    public function testSmallPlayerCanGiveUpToAQuarterOfHisWeeklyProduction(): void
    {
        [$guard, $persisted] = $this->guard(alreadyGiven: 0);
        $limit = floor(0.25 * $guard->weeklyProduction($this->small));
        self::assertGreaterThan(0, $limit);

        $guard->checkAndRecord($this->small, $this->big, $limit, $this->now);
        self::assertCount(1, $persisted->getArrayCopy(), 'the gift is logged');
        self::assertInstanceOf(ResourceGift::class, $persisted[0]);

        try {
            $guard->checkAndRecord($this->small, $this->big, $limit + 1, $this->now);
            self::fail('more than a quarter is refused');
        } catch (GameException $e) {
            self::assertStringContainsString('Pushing-Schutz', self::text($e));
        }
    }

    public function testEarlierGiftsWithinSevenDaysCount(): void
    {
        [$guard] = $this->guard(alreadyGiven: 0);
        $limit = floor(0.25 * $guard->weeklyProduction($this->small));
        [$guard] = $this->guard(alreadyGiven: $limit - 10);

        $guard->checkAndRecord($this->small, $this->big, 10, $this->now);
        $this->expectException(GameException::class);
        $guard->checkAndRecord($this->small, $this->big, 11, $this->now);
    }

    public function testBiggerPlayersMayGiveFreely(): void
    {
        [$guard, $persisted] = $this->guard(alreadyGiven: 1e9);
        $guard->checkAndRecord($this->big, $this->small, 1e7, $this->now);
        self::assertCount(1, $persisted->getArrayCopy(), 'logged, but not limited');
    }

    /** @return array{PushingGuard, \ArrayObject<int, object>} */
    private function guard(float $alreadyGiven): array
    {
        $persisted = new \ArrayObject();
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($alreadyGiven);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('persist')->willReturnCallback(static function (object $o) use ($persisted): void { $persisted[] = $o; });

        return [new PushingGuard($em, new Economy(1.0)), $persisted];
    }

    private function player(string $name, int $system, int $level): User
    {
        $user = new User($name, $this->now);
        $planet = new Planet($user, 'Heim', 1, $system, 1, $this->now);
        foreach ([BuildingType::IronMine, BuildingType::TitaniumMine, BuildingType::CommandCenter] as $type) {
            $planet->setBuildingLevel($type, $level);
        }

        return $user;
    }
}
