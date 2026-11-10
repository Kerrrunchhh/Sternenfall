<?php

namespace App\Tests\Game;

use App\Entity\Ticker;
use App\Entity\TickerPost;
use App\Entity\User;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\TickerService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Long-range tickers: invitations, owner rights, handing over. */
final class TickerTest extends TestCase
{
    private \DateTimeImmutable $now;
    /** @var array<string, User> */
    private array $users = [];
    private TickerService $service;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01 12:00');
        foreach (['Anna', 'Bert', 'Carla'] as $i => $name) {
            $this->users[$name] = new User($name, $this->now);
            (new \ReflectionProperty(User::class, 'id'))->setValue($this->users[$name], $i + 1);
        }
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(fn (array $by): ?User => $this->users[$by['username']] ?? null);
        $em = $this->createStub(EntityManagerInterface::class);
        $this->service = new TickerService($em, $users, new Inbox($em, new MockClock($this->now)), new MockClock($this->now));
    }

    public function testInvitedPlayersJoinOnlyAfterAccepting(): void
    {
        $ticker = $this->service->open($this->users['Anna'], 'Händlerfunk');
        self::assertTrue($ticker->isMember($this->users['Anna']), 'the owner is a member right away');

        $this->service->invite($ticker, $this->users['Anna'], 'Bert');
        self::assertFalse($ticker->isMember($this->users['Bert']));
        $this->assertRefused(fn () => $this->service->post($ticker, $this->users['Bert'], 'Hallo'));

        $this->service->accept($ticker->membership($this->users['Bert']), $this->users['Bert']);
        self::assertTrue($ticker->isMember($this->users['Bert']));
        self::assertSame('Hallo', $this->service->post($ticker, $this->users['Bert'], ' Hallo ')->getBody());
    }

    public function testOnlyTheOwnerInvitesRemovesAndDeletesPosts(): void
    {
        $ticker = $this->tickerWithBert();
        $post = new TickerPost($ticker, $this->users['Anna'], 'Text', $this->now);

        $this->assertRefused(fn () => $this->service->invite($ticker, $this->users['Bert'], 'Carla'));
        $this->assertRefused(fn () => $this->service->deletePost($post, $this->users['Bert']));
        $this->assertRefused(fn () => $this->service->remove($ticker, $this->users['Bert'], $ticker->membership($this->users['Anna'])));
        $this->assertRefused(fn () => $this->service->invite($ticker, $this->users['Anna'], 'Bert'), 'already a member');

        $this->service->remove($ticker, $this->users['Anna'], $ticker->membership($this->users['Bert']));
        self::assertNull($ticker->membership($this->users['Bert']));
    }

    public function testOwnerHandsOverAndMayLeaveOnlyAfterwards(): void
    {
        $ticker = $this->tickerWithBert();
        $this->assertRefused(fn () => $this->service->leave($ticker->membership($this->users['Anna']), $this->users['Anna']));
        $this->assertRefused(fn () => $this->service->handOver($ticker, $this->users['Anna'], $ticker->invite($this->users['Carla'], $this->now)), 'only to members, not to the invited');

        $this->service->handOver($ticker, $this->users['Anna'], $ticker->membership($this->users['Bert']));
        self::assertTrue($ticker->isOwnedBy($this->users['Bert']));
        $this->assertRefused(fn () => $this->service->invite($ticker, $this->users['Anna'], 'Carla'), 'the old owner lost his rights');

        $this->service->leave($ticker->membership($this->users['Anna']), $this->users['Anna']);
        self::assertFalse($ticker->isMember($this->users['Anna']));
    }

    private function tickerWithBert(): Ticker
    {
        $ticker = new Ticker('Funk', $this->users['Anna'], $this->now);
        $ticker->invite($this->users['Bert'], $this->now)->accept($this->now);

        return $ticker;
    }

    private function assertRefused(callable $action, string $message = ''): void
    {
        try {
            $action();
            self::fail('Expected a GameException. '.$message);
        } catch (GameException) {
            $this->addToAssertionCount(1);
        }
    }
}
