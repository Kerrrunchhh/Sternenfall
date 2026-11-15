<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\AlliancePost;
use App\Entity\User;
use App\Game\AllianceRank;
use App\Game\AllianceService;
use App\Game\GameException;
use App\Game\ImageStore;
use App\Game\Inbox;
use App\Game\LifeForm;
use App\Game\TickerService;
use App\Repository\AllianceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Validator\Validation;

/** Authors may edit their posts on the black board and in tickers – only their own. */
class PostEditTest extends TestCase
{
    private \DateTimeImmutable $now;
    private MockClock $clock;
    /** @var array<string, User> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-15 10:00:00');
        $this->clock = new MockClock($this->now);
        foreach (['Anna', 'Bert', 'Carla'] as $i => $name) {
            $this->users[$name] = new User($name, $this->now);
            (new \ReflectionProperty(User::class, 'id'))->setValue($this->users[$name], $i + 1);
        }
    }

    public function testTheAuthorEditsHisBoardPostOthersDoNot(): void
    {
        $alliance = new Alliance('Orden', 'ORD', LifeForm::Settler, $this->now);
        (new \ReflectionProperty(Alliance::class, 'id'))->setValue($alliance, 7);
        $this->users['Anna']->joinAlliance($alliance, AllianceRank::Member);
        $this->users['Bert']->joinAlliance($alliance, AllianceRank::Founder);
        $post = new AlliancePost($alliance, $this->users['Anna'], 'Treffen um acht', $this->now);
        $service = $this->alliances();

        $this->clock->modify('+1 hour');
        $service->editPost($this->users['Anna'], $post, ' Treffen um neun ');

        self::assertSame('Treffen um neun', $post->getBody());
        self::assertEquals($this->now->modify('+1 hour'), $post->getEditedAt());
        $this->assertRefused(fn () => $service->editPost($this->users['Bert'], $post, 'umgeschrieben'), 'not even the founder rewrites other posts');
        $this->assertRefused(fn () => $service->editPost($this->users['Anna'], $post, '   '), 'not empty');

        $this->users['Anna']->leaveAlliance();
        $this->assertRefused(fn () => $service->editPost($this->users['Anna'], $post, 'noch was'), 'gone from the alliance: no more edits');
    }

    public function testTheFounderDeletesAnyBoardPostOthersOnlyTheirOwn(): void
    {
        $alliance = new Alliance('Orden', 'ORD', LifeForm::Settler, $this->now);
        (new \ReflectionProperty(Alliance::class, 'id'))->setValue($alliance, 7);
        $this->users['Anna']->joinAlliance($alliance, AllianceRank::Member);
        $this->users['Bert']->joinAlliance($alliance, AllianceRank::Founder);
        $this->users['Carla']->joinAlliance($alliance, AllianceRank::Member);
        $post = new AlliancePost($alliance, $this->users['Anna'], 'Hallo', $this->now);
        $service = $this->alliances();

        $this->assertRefused(fn () => $service->deletePost($this->users['Carla'], $post), 'another member may not');
        $service->deletePost($this->users['Bert'], $post);
        $this->addToAssertionCount(1); // the founder may
    }

    public function testTheTickerOwnerDeletesAnyPost(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(fn (array $by): ?User => $this->users[$by['username']] ?? null);
        $service = new TickerService($em, $users, new Inbox($em, $this->clock), $this->clock);
        $ticker = $service->open($this->users['Anna'], 'Alte Allianz');
        $service->invite($ticker, $this->users['Anna'], 'Bert');
        $service->accept($ticker->membership($this->users['Bert']), $this->users['Bert']);
        $post = $service->post($ticker, $this->users['Bert'], 'Spam');

        $this->assertRefused(fn () => $service->deletePost($post, $this->users['Bert']), 'members don\'t delete');
        $service->deletePost($post, $this->users['Anna']);
        $this->addToAssertionCount(1); // the owner may
    }

    public function testTheAuthorEditsHisTickerPostAsLongAsHeIsAMember(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(fn (array $by): ?User => $this->users[$by['username']] ?? null);
        $service = new TickerService($em, $users, new Inbox($em, $this->clock), $this->clock);
        $ticker = $service->open($this->users['Anna'], 'Alte Allianz');
        $service->invite($ticker, $this->users['Anna'], 'Bert');
        $service->accept($ticker->membership($this->users['Bert']), $this->users['Bert']);
        $post = $service->post($ticker, $this->users['Bert'], 'Hallo');

        $service->editPost($post, $this->users['Bert'], 'Hallo zusammen');

        self::assertSame('Hallo zusammen', $post->getBody());
        self::assertNotNull($post->getEditedAt());
        $this->assertRefused(fn () => $service->editPost($post, $this->users['Anna'], 'zensiert'), 'the owner deletes, but doesn\'t rewrite');

        $service->leave($ticker->membership($this->users['Bert']), $this->users['Bert']);
        $this->assertRefused(fn () => $service->editPost($post, $this->users['Bert'], 'nachträglich'));
    }

    private function alliances(): AllianceService
    {
        $em = $this->createStub(EntityManagerInterface::class);

        return new AllianceService($em, $this->createStub(AllianceRepository::class), $this->createStub(UserRepository::class), new Inbox($em, $this->clock), new LockFactory(new InMemoryStore()), $this->clock, new ImageStore(Validation::createValidator(), sys_get_temp_dir()));
    }

    private function assertRefused(callable $action, string $message = ''): void
    {
        try {
            $action();
            self::fail('expected a refusal: '.$message);
        } catch (GameException) {
            $this->addToAssertionCount(1);
        }
    }
}
