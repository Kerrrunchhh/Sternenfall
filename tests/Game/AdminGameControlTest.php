<?php

namespace App\Tests\Game;

use App\Entity\AttackBan;
use App\Entity\BattleReport;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\AttackBans;
use App\Game\BattleGroup;
use App\Game\BattleReportRefunds;
use App\Game\BattleResult;
use App\Game\Economy;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Game\ShipType;
use App\Repository\AttackBanRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Admin game control: the general attack ban and refunds for annulled combat reports. */
final class AdminGameControlTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-12 10:00:00');
    }

    public function testAnAttackBanIsActiveUntilItEndsOrIsLifted(): void
    {
        $timed = new AttackBan('Server langsam', $this->now->modify('-1 hour'), $this->now->modify('+2 hours'));
        self::assertTrue($timed->isActive($this->now));
        self::assertFalse($timed->isActive($this->now->modify('+2 hours')), 'over when the time is up');

        $open = new AttackBan('Wartung', $this->now->modify('-1 hour'));
        self::assertTrue($open->isActive($this->now->modify('+30 days')), 'without an end it lasts until lifted');
        $open->lift($this->now);
        self::assertFalse($open->isActive($this->now->modify('+1 minute')));

        $bans = $this->bans([$timed]);
        self::assertSame($timed, $bans->current());
        self::assertStringContainsString('Server langsam', (string) $bans->refusal()?->trans(new \Symfony\Component\Translation\IdentityTranslator()));
        self::assertNull($this->bans([])->refusal(), 'no ban, no refusal');
    }

    public function testImposingNeedsAReasonAndASensibleDuration(): void
    {
        foreach ([['', null, 'Grund'], ['Wartung', 0, 'Stunden'], ['Wartung', AttackBans::MAX_HOURS + 1, 'Stunden']] as [$reason, $hours, $error]) {
            try {
                $this->bans([])->impose($reason, $hours);
                self::fail('expected: '.$error);
            } catch (GameException $e) {
                self::assertStringContainsString($error, $e->getMessage());
            }
        }
        $ban = $this->bans([])->impose('Wartung', 6);
        self::assertEquals($this->now->modify('+6 hours'), $ban->getEndsAt());
    }

    public function testBattleResultKnowsWhatEveryoneLost(): void
    {
        [$attacker, $defender] = [$this->user('angreifer', 1), $this->user('verteidiger', 2)];
        $lost = new BattleGroup(BattleGroup::DEFENDER, $defender, ShipType::Pfeil, 10);
        $lost->hp = 0;
        $result = new BattleResult([$lost, new BattleGroup(BattleGroup::ATTACKER, $attacker, ShipType::Lanze, 5)], false);

        self::assertSame([2 => ['name' => 'verteidiger', 'ships' => ['pfeil' => 10]], 1 => ['name' => 'angreifer', 'ships' => []]], $result->losses());
    }

    public function testAnnullingGivesTheShipsBackOnTheChosenPlanet(): void
    {
        $player = $this->user('pechvogel', 7);
        $home = new Planet($player, 'Heim', 1, 1, 1, $this->now);
        (new \ReflectionProperty(Planet::class, 'id'))->setValue($home, 70);
        $report = new BattleReport(str_repeat('a', 24), 'Angriff', 'Text', $this->now);
        $report->setLosses([7 => ['name' => 'pechvogel', 'ships' => ['pfeil' => 10]]]);
        $refunds = $this->refunds($player);

        $this->expectExceptionOnce(fn () => $refunds->annul($report, '', [], []), 'Grund');
        $this->expectExceptionOnce(fn () => $refunds->annul($report, 'Serverausfall', [7 => ['pfeil' => '10']], [7 => 999]), 'Planeten');
        self::assertFalse($report->isAnnulled(), 'nothing happens when the input is wrong');

        self::assertSame(1, $refunds->annul($report, 'Serverausfall', [7 => ['pfeil' => '10', 'falke' => '0', 'quatsch' => '5']], [7 => 70]));
        self::assertTrue($report->isAnnulled());
        self::assertSame(10, $home->getShipCount(ShipType::Pfeil));
        self::assertSame(['pfeil' => 10], $report->getRefunds()[0]['ships']);
    }

    private function expectExceptionOnce(callable $action, string $message): void
    {
        try {
            $action();
            self::fail('expected: '.$message);
        } catch (GameException $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    private function user(string $name, int $id): User
    {
        $user = new User($name, $this->now);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    /** @param list<AttackBan> $notLifted */
    private function bans(array $notLifted): AttackBans
    {
        $repository = $this->createStub(AttackBanRepository::class);
        $repository->method('findNotLifted')->willReturn($notLifted);

        return new AttackBans($this->createStub(EntityManagerInterface::class), $repository, new MockClock($this->now));
    }

    private function refunds(User $player): BattleReportRefunds
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $clock = new MockClock($this->now);
        $economy = new Economy(1.0);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(static fn (int $id): ?User => $id === $player->getId() ? $player : null);

        return new BattleReportRefunds($em, new PlanetService($em, new LockFactory(new InMemoryStore()), $clock, new PlanetUpdater($economy), $economy), $users, new Inbox($em, $clock), $clock);
    }
}
