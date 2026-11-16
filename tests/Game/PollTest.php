<?php

namespace App\Tests\Game;

use App\Entity\Message;
use App\Entity\Poll;
use App\Entity\PollVote;
use App\Entity\User;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\PollService;
use App\Repository\PollRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Polls: every player answers once, the result counts every ticked answer and keeps the reasons. */
class PollTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-16 20:00:00');
    }

    public function testAPollGoesToEveryPlayingPlayer(): void
    {
        $admin = new User('Chefin');
        $guild = new User('Handelsgilde');
        $guild->markSystem();
        $gone = new User('weg');
        $gone->deactivate(new \DateTimeImmutable());
        $bert = new User('bert');
        $bert->setLocale('en');
        $service = $this->service([$admin, new User('anna'), $bert, $guild, $gone]);

        $poll = $service->create($admin, ' Neue Lebensform? ', ["Ja\n", '', 'Nein', 'Nein', 'Egal'], false, 72, 'A new life form?', ['Yes', 'No', "Don't care"]);

        self::assertSame(['Ja', 'Nein', 'Egal'], $poll->getOptions(), 'empty and doubled answers dropped');
        self::assertSame(['Yes', 'No', "Don't care"], $poll->optionsIn('en'));
        self::assertSame('A new life form?', $poll->questionIn('en'));
        self::assertSame('Neue Lebensform?', $poll->questionIn('de'));
        self::assertEquals(new \DateTimeImmutable('2026-11-19 20:00:00'), $poll->getEndsAt());
        $messages = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Message));
        self::assertCount(3, $messages, 'admin, anna and bert – not the guild, not deactivated accounts');
        $toBert = array_values(array_filter($messages, static fn (Message $m): bool => $m->getRecipient() === $bert))[0];
        self::assertStringContainsString('A new life form?', $toBert->getSubject(), 'each player gets the question in his language');
    }

    public function testTheAnnouncementMailGoesToNewsSubscribersInTheirLanguage(): void
    {
        $anna = $this->subscriber('anna', 'de', true);
        $bert = $this->subscriber('bert', 'en', true);
        $carla = $this->subscriber('carla', 'de', false);
        $sent = [];
        $mailer = $this->createStub(\Symfony\Component\Mailer\MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (\Symfony\Component\Mime\RawMessage $m) use (&$sent): void {
            $sent[] = $m;
        });
        $urls = $this->createStub(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p = [], int $type = 1): string => (\Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL === $type ? 'https://example.org' : '').'/umfragen#umfrage-1');
        $gameMailer = new \App\Game\GameMailer($mailer, new \Symfony\Component\HttpFoundation\UriSigner('secret'), $urls, new \Psr\Log\NullLogger(), 'noreply@example.org', 'Starfall', new \Symfony\Component\Translation\IdentityTranslator());
        $service = $this->service([$anna, $bert, $carla], urls: $urls, mailer: $gameMailer);
        $poll = $service->create(new User('Chefin'), 'Neue Lebensform?', ['Ja', 'Nein'], false, 24, 'A new life form?', ['Yes', 'No']);

        self::assertSame(2, $service->mailAnnouncement($poll), 'anna and bert subscribed to news, carla did not');
        $bodies = array_map(static fn (\Symfony\Component\Mime\Email $m): string => (string) $m->getTextBody(), $sent);
        self::assertCount(2, $sent, 'one mail per language');
        self::assertNotEmpty(array_filter($bodies, static fn (string $b): bool => str_contains($b, 'A new life form?') && str_contains($b, '• Yes')));
        self::assertNotEmpty(array_filter($bodies, static fn (string $b): bool => str_contains($b, 'Neue Lebensform?') && str_contains($b, 'https://example.org/umfragen#umfrage-1')));
    }

    public function testInvalidPollsAreRefused(): void
    {
        $service = $this->service([]);
        $admin = new User('Chefin');

        $this->assertRefused(fn () => $service->create($admin, '', ['a', 'b'], false, 24, 'Q', ['a', 'b']));
        $this->assertRefused(fn () => $service->create($admin, 'Frage', ['nur eine'], false, 24, 'Q', ['only one']));
        $this->assertRefused(fn () => $service->create($admin, 'Frage', range(1, 11), false, 24, 'Q', range(1, 11)));
        $this->assertRefused(fn () => $service->create($admin, 'Frage', ['a', 'b'], false, 0, 'Q', ['a', 'b']));
        $this->assertRefused(fn () => $service->create($admin, 'Frage', ['a', 'b'], false, 24), 'the English version is needed');
        $this->assertRefused(fn () => $service->create($admin, 'Frage', ['a', 'b', 'c'], false, 24, 'Q', ['a', 'b']), 'as many answers in English');
    }

    public function testEveryPlayerVotesOnceWhileItRuns(): void
    {
        $anna = new User('anna');
        $poll = new Poll('Frage', ['A', 'B', 'C'], false, $this->clock->now(), $this->clock->now()->modify('+1 day'), null);
        $votes = [];
        $service = $this->service([], $votes);

        $this->assertRefused(fn () => $service->vote($poll, $anna, [], ''), 'at least one answer');
        $this->assertRefused(fn () => $service->vote($poll, $anna, [0, 1], ''), 'only one answer here');
        $this->assertRefused(fn () => $service->vote($poll, $anna, [7], ''), 'no such answer');
        $service->vote($poll, $anna, ['1'], '  Weil B am besten ist  ');
        $vote = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof PollVote))[0];
        self::assertSame([1], $vote->getChoices());
        self::assertSame('Weil B am besten ist', $vote->getReason());

        $votes[] = $vote;
        $this->assertRefused(fn () => $service->vote($poll, $anna, [0], ''), 'only once');

        $this->clock->modify('+2 days');
        $this->assertRefused(fn () => $this->service([])->vote($poll, new User('bert'), [0], ''), 'over');
    }

    public function testTheResultCountsEveryTickedAnswer(): void
    {
        $poll = new Poll('Was baust du?', ['Minen', 'Schiffe', 'Verteidigung'], true, $this->clock->now(), $this->clock->now(), null);
        $votes = [
            new PollVote($poll, new User('a'), [0, 1], 'Beides wichtig', $this->clock->now()),
            new PollVote($poll, new User('b'), [1], null, $this->clock->now()),
            new PollVote($poll, new User('c'), [1, 2], '', $this->clock->now()),
        ];

        $result = PollService::result($poll, $votes);

        self::assertSame(3, $result['voters']);
        self::assertSame([1, 3, 1], $result['counts']);
        self::assertCount(1, $result['reasons'], 'empty reasons are no reasons');
        self::assertSame('Beides wichtig', $result['reasons'][0]['text']);
    }

    /**
     * @param list<User>     $players
     * @param list<PollVote> $votes   votes already given (by reference, so tests can add)
     */
    private function service(array $players, array &$votes = [], ?\Symfony\Component\Routing\Generator\UrlGeneratorInterface $urls = null, ?\App\Game\GameMailer $mailer = null): PollService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        $users = $this->createStub(UserRepository::class);
        $users->method('findAll')->willReturn($players);
        $polls = $this->createStub(PollRepository::class);
        $polls->method('voteOf')->willReturnCallback(static function (Poll $p, User $u) use (&$votes): ?PollVote {
            foreach ($votes as $v) {
                if ($v->getPoll() === $p && $v->getUser() === $u) {
                    return $v;
                }
            }

            return null;
        });

        return new PollService($em, $polls, $users, new Inbox($em, $this->clock), $this->clock, $urls, $mailer);
    }

    private function subscriber(string $name, string $locale, bool $news): User
    {
        $user = new User($name);
        $user->setLocale($locale);
        $user->setEmail($name.'@example.org');
        $user->verifyEmail(new \DateTimeImmutable());
        $user->setMailTopics($news ? ['news'] : ['attack']);

        return $user;
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
