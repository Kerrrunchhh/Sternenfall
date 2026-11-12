<?php

namespace App\Tests\Game;

use App\Entity\Bounty;
use App\Entity\User;
use App\Game\BattleGroup;
use App\Game\BattleResult;
use App\Game\BountyService;
use App\Game\Inbox;
use App\Game\ShipType;
use App\Repository\BountyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Bounties: destroyed spaceship shields of the target count for the hunters, over several battles. */
final class BountyTest extends TestCase
{
    private \DateTimeImmutable $now;
    private User $client;
    private User $target;
    private User $hunter;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-12 10:00:00');
        [$this->client, $this->target, $this->hunter] = [new User('auftraggeber', $this->now), new User('ziel', $this->now), new User('jaeger', $this->now)];
        foreach ([$this->client, $this->target, $this->hunter] as $i => $user) {
            (new \ReflectionProperty(User::class, 'id'))->setValue($user, $i + 1);
        }
    }

    public function testExampleFleetHasSevenHundredFiftyShieldPoints(): void
    {
        // 20 × Pfeil (10) + 10 × Falke (25) + 5 × Lanze (60)
        self::assertSame(750, BountyService::exampleShield());
    }

    public function testOnlyDestroyedSpaceshipsOfTheTargetCountSharedByFleetStrength(): void
    {
        $second = new User('zweiter', $this->now);
        $result = $this->battle([
            $this->destroyed(BattleGroup::DEFENDER, $this->target, ShipType::Pfeil, 20),
            $this->destroyed(BattleGroup::DEFENDER, $this->target, ShipType::LaserTurret, 10),
            new BattleGroup(BattleGroup::ATTACKER, $this->hunter, ShipType::Pfeil, 30),
            new BattleGroup(BattleGroup::ATTACKER, $second, ShipType::Pfeil, 10),
        ]);

        $shares = $result->shipShieldDestroyedOf($this->target);
        self::assertCount(2, $shares);
        self::assertEqualsWithDelta(200, array_sum(array_column($shares, 'shield')), 0.001, '20 Pfeile à 10 – turrets don’t count');
        self::assertEqualsWithDelta(150, $shares[0]['shield'], 0.001, 'three quarters of the strength, three quarters of the shield');
        self::assertSame([], $result->shipShieldDestroyedOf($this->hunter), 'nothing of the hunters was destroyed');
    }

    public function testProgressAddsUpOverBattlesAndTheFirstToReachItGetsTheReward(): void
    {
        $bounty = new Bounty($this->client, $this->target, 5000, 300, 7, '', $this->now);
        $service = $this->service([$bounty]);
        $before = $this->hunter->getCredits();

        // First battle: 20 Pfeile = 200 of 300.
        $lines = $service->record($this->battle([
            $this->destroyed(BattleGroup::DEFENDER, $this->target, ShipType::Pfeil, 20),
            new BattleGroup(BattleGroup::ATTACKER, $this->hunter, ShipType::Lanze, 5),
        ]));
        self::assertTrue($bounty->isOpen());
        self::assertEqualsWithDelta(200, $bounty->huntOf($this->hunter)?->getShield(), 0.001);
        self::assertNotSame([], $lines, 'the battle report mentions the bounty');

        // Second battle, the target attacks this time: 4 Falken = 100 more, 300 reached.
        $service->record($this->battle([
            $this->destroyed(BattleGroup::ATTACKER, $this->target, ShipType::Falke, 4),
            new BattleGroup(BattleGroup::DEFENDER, $this->hunter, ShipType::Lanze, 5),
        ]));
        self::assertSame(Bounty::DONE, $bounty->getStatus());
        self::assertSame($this->hunter, $bounty->getWinner());
        self::assertEqualsWithDelta($before + 5000, $this->hunter->getCredits(), 0.001);
    }

    public function testTheClientCannotHuntHisOwnBounty(): void
    {
        $bounty = new Bounty($this->client, $this->target, 5000, 100, 7, '', $this->now);
        $this->service([$bounty])->record($this->battle([
            $this->destroyed(BattleGroup::DEFENDER, $this->target, ShipType::Pfeil, 20),
            new BattleGroup(BattleGroup::ATTACKER, $this->client, ShipType::Lanze, 5),
        ]));

        self::assertTrue($bounty->isOpen());
        self::assertNull($bounty->huntOf($this->client));
    }

    private function destroyed(string $side, User $owner, ShipType $type, int $count): BattleGroup
    {
        $group = new BattleGroup($side, $owner, $type, $count);
        $group->hp = 0;

        return $group;
    }

    /** @param list<BattleGroup> $groups */
    private function battle(array $groups): BattleResult
    {
        return new BattleResult($groups, false);
    }

    /** @param list<Bounty> $open */
    private function service(array $open): BountyService
    {
        $repository = $this->createStub(BountyRepository::class);
        $repository->method('findOpenOn')->willReturnCallback(static fn (User $u): array => array_values(array_filter($open, static fn (Bounty $b): bool => $b->isOpen() && $b->getTarget()->isSameAs($u))));
        $em = $this->createStub(EntityManagerInterface::class);
        $clock = new MockClock($this->now);

        return new BountyService($em, $repository, $this->createStub(UserRepository::class), new Inbox($em, $clock), $clock);
    }
}
