<?php

namespace App\Tests\Game;

use App\Entity\Alliance;
use App\Entity\Contact;
use App\Entity\PlayerMessage;
use App\Entity\User;
use App\Game\ContactService;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\LifeForm;
use App\Game\Mailbox;
use App\Game\Presence;
use App\Repository\ContactRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Letters between players, quick contacts and who sees whose online status. */
final class PlayerMailTest extends TestCase
{
    private \DateTimeImmutable $now;
    private User $anna;
    private User $bert;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01 12:00:00');
        $this->anna = $this->user('Anna', 1);
        $this->bert = $this->user('Bert', 2);
    }

    public function testLetterReachesTheNamedPlayer(): void
    {
        $letter = $this->mailbox()->send($this->anna, ' Bert ', '', "Hallo!\nTreffen bei [3:144:9]?");

        self::assertSame($this->bert, $letter->getRecipient());
        self::assertSame('(kein Betreff)', $letter->getSubject());
        self::assertFalse($letter->isRead());
    }

    public function testUnknownSelfOrEmptyLettersAreRefused(): void
    {
        foreach ([['Niemand', 'Text'], ['Anna', 'Text'], ['Bert', '   ']] as [$to, $body]) {
            try {
                $this->mailbox()->send($this->anna, $to, 'Betreff', $body);
                self::fail("Brief an $to hätte abgelehnt werden müssen");
            } catch (GameException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnlyTheRecipientReadingMarksItRead(): void
    {
        $letter = new PlayerMessage($this->anna, $this->bert, 'Hi', 'Text', $this->now);
        $this->mailbox()->open($letter, $this->anna);
        self::assertFalse($letter->isRead(), 'the sender looking at it does not count');

        $this->mailbox()->open($letter, $this->bert);
        self::assertTrue($letter->isRead());

        $this->expectException(GameException::class);
        $this->mailbox()->open($letter, $this->user('Carla', 3));
    }

    public function testBothSidesDeleteTheirOwnCopy(): void
    {
        $letter = new PlayerMessage($this->anna, $this->bert, 'Hi', 'Text', $this->now);
        $carla = $this->user('Carla', 3);

        self::assertSame(0, $this->mailbox()->delete([$letter], $carla), 'strangers cannot delete it');
        self::assertSame(1, $this->mailbox()->delete([$letter], $this->bert));
        self::assertFalse($letter->isVisibleTo($this->bert));
        self::assertTrue($letter->isVisibleTo($this->anna), 'the sender still has it among the sent ones');
        self::assertTrue($letter->deleteFor($this->anna), 'gone for good once both deleted it');
    }

    public function testReplyQuotesTheLetter(): void
    {
        $letter = new PlayerMessage($this->anna, $this->bert, 'Angriff?', "Zeile 1\nZeile 2", $this->now);

        [$subject, $body] = Mailbox::replyDraft($letter);
        self::assertSame('Re: Angriff?', $subject);
        self::assertStringContainsString("Anna schrieb am 01.01.2026 13:00:\n> Zeile 1\n> Zeile 2", $body);
        self::assertSame('Re: Angriff?', Mailbox::replyDraft(new PlayerMessage($this->bert, $this->anna, $subject, 'ok', $this->now))[0], 'no "Re: Re:"');
    }

    public function testContactNeedsTheOtherToAccept(): void
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findAccepted')->willReturn([]);
        $service = new ContactService($this->createStub(EntityManagerInterface::class), $contacts, new Inbox($this->createStub(EntityManagerInterface::class), new MockClock($this->now)), new MockClock($this->now));

        $contact = $service->request($this->anna, $this->bert);
        self::assertFalse($contact->isAccepted());

        try {
            $service->accept($contact, $this->anna);
            self::fail('the one who asked cannot accept');
        } catch (GameException) {
        }
        $service->accept($contact, $this->bert);
        self::assertTrue($contact->isAccepted());
        self::assertSame($this->anna, $contact->other($this->bert));
    }

    public function testAskingBackAcceptsAnOpenRequest(): void
    {
        $open = new Contact($this->bert, $this->anna, $this->now);
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findBetween')->willReturn($open);
        $contacts->method('findAccepted')->willReturn([]);
        $service = new ContactService($this->createStub(EntityManagerInterface::class), $contacts, new Inbox($this->createStub(EntityManagerInterface::class), new MockClock($this->now)), new MockClock($this->now));

        self::assertSame($open, $service->request($this->anna, $this->bert));
        self::assertTrue($open->isAccepted());
    }

    public function testStatusShowsOnlineOrHoursOffline(): void
    {
        $presence = $this->presence([]);

        self::assertSame('noch nie online', $presence->describe($this->bert));
        $this->bert->markSeen($this->now->modify('-4 minutes'));
        self::assertTrue($presence->isOnline($this->bert));
        self::assertSame('online (<1h)', $presence->describe($this->bert));
        $this->bert->markSeen($this->now->modify('-150 minutes'), true);
        $this->bert->markSeen($this->now->modify('-2 minutes'));
        self::assertSame('online (2h)', $presence->describe($this->bert), 'online since the session started');
        $this->bert->markSeen($this->now->modify('-20 minutes'));
        self::assertSame('offline (<1h)', $presence->describe($this->bert));
        $this->bert->markSeen($this->now->modify('-53 hours -10 minutes'));
        self::assertSame('offline (53h)', $presence->describe($this->bert));
    }

    public function testOnlyAllianceMatesAndContactsSeeTheStatus(): void
    {
        $carla = $this->user('Carla', 3);
        $contact = new Contact($this->anna, $carla, $this->now);
        $contact->accept($this->now);
        $presence = $this->presence([$contact]);

        self::assertFalse($presence->isVisibleTo($this->anna, $this->bert), 'strangers see nothing');
        self::assertTrue($presence->isVisibleTo($this->anna, $carla), 'quick contact');

        $alliance = new Alliance('Orden', 'ORD', LifeForm::Guardian, $this->now);
        (new \ReflectionProperty(Alliance::class, 'id'))->setValue($alliance, 9);
        $this->anna->joinAlliance($alliance, \App\Game\AllianceRank::Member);
        $this->bert->joinAlliance($alliance, \App\Game\AllianceRank::Member);
        self::assertTrue($presence->isVisibleTo($this->anna, $this->bert), 'alliance mate');
    }

    private function mailbox(): Mailbox
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneBy')->willReturnCallback(fn (array $by): ?User => ['Anna' => $this->anna, 'Bert' => $this->bert][$by['username']] ?? null);

        return new Mailbox($this->createStub(EntityManagerInterface::class), $users, new MockClock($this->now));
    }

    /** @param list<Contact> $accepted */
    private function presence(array $accepted): Presence
    {
        $contacts = $this->createStub(ContactRepository::class);
        $contacts->method('findAccepted')->willReturn($accepted);

        return new Presence($this->createStub(Connection::class), $contacts, new MockClock($this->now));
    }

    private function user(string $name, int $id): User
    {
        $user = new User($name, $this->now);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
