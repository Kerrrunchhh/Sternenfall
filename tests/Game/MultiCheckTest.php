<?php

namespace App\Tests\Game;

use App\Entity\Message;
use App\Entity\Ticket;
use App\Entity\User;
use App\Game\Economy;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Game\SupportService;
use App\Game\TicketKind;
use App\Game\TicketStatus;
use App\Game\VacationService;
use App\Repository\FleetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mailer\MailerInterface;

/** The team asks a player about a possible multi account – anonymously – and he can explain himself. */
class MultiCheckTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testThePlayerIsAskedByTheSupportTeamAndMayAnswer(): void
    {
        $player = new User('verdaechtig');
        $other = new User('zweitaccount');
        $moderator = new User('Moderatorin');
        $moderator->setRoles(['ROLE_MODERATOR']);
        $support = $this->support([$moderator]);

        $ticket = $support->askAboutMulti($player, $other, 'Frage zu deinem Account', 'Bitte erkläre uns, wie es dazu kommt.');

        self::assertSame(TicketKind::MultiCheck, $ticket->getKind());
        self::assertTrue($ticket->isByAuthor($player), 'the dialog belongs to the player');
        self::assertSame($other, $ticket->getReported(), 'the other account is noted for the team');
        self::assertSame(TicketStatus::Question, $ticket->getStatus(), 'waiting for his answer');
        $message = $this->messages()[0];
        self::assertStringContainsString('Support-Team', $message->getSubject());
        self::assertStringNotContainsString('Moderatorin', $message->getSubject().$message->getBody(), 'nobody by name');
        self::assertStringNotContainsString('zweitaccount', $message->getSubject().$message->getBody(), 'not the other account either');

        $this->persisted = [];
        $support->reply($ticket, $player, 'Das ist mein Bruder, wir teilen den PC.');
        self::assertSame(TicketStatus::Open, $ticket->getStatus(), 'back with the team');
        self::assertCount(1, $ticket->getComments());

        $this->persisted = [];
        $support->answer($ticket, $moderator, 'Danke, alles in Ordnung.', TicketStatus::Accepted);
        $toPlayer = array_values(array_filter($this->messages(), static fn (Message $m): bool => $m->getRecipient() === $player));
        self::assertCount(1, $toPlayer);
        self::assertStringNotContainsString('Moderatorin', $toPlayer[0]->getSubject().$toPlayer[0]->getBody());
    }

    public function testTheTeamEditsItsOwnMessagesAdminsAllOfTheTeam(): void
    {
        $player = new User('spieler');
        $moderator = new User('Moderatorin');
        $moderator->setRoles(['ROLE_MODERATOR']);
        $colleague = new User('Kollege');
        $colleague->setRoles(['ROLE_MODERATOR']);
        $admin = new User('Chefin');
        $admin->setRoles(['ROLE_ADMIN']);
        $support = $this->support([$moderator, $colleague, $admin]);
        $ticket = $support->askAboutMulti($player, null, 'Frage', 'Erster Text');
        $support->reply($ticket, $player, 'Meine Antwort');
        $support->answer($ticket, $moderator, 'Tippfeler', TicketStatus::Question);
        [$fromPlayer, $fromModerator] = $ticket->getComments()->toArray();

        $support->editTeamMessage($ticket, $fromModerator, $moderator, 'Tippfehler');
        self::assertSame('Tippfehler', $fromModerator->getBody());
        self::assertNotNull($fromModerator->getEditedAt());

        $support->editTeamMessage($ticket, null, $colleague, 'Neuer erster Text');
        self::assertSame('Neuer erster Text', $ticket->getBody(), 'the opening message of a multi check: anybody of the team');
        self::assertNotNull($ticket->getBodyEditedAt());

        self::assertFalse(SupportService::mayEdit($ticket, $fromModerator, $colleague), 'not the answer of a colleague');
        self::assertTrue(SupportService::mayEdit($ticket, $fromModerator, $admin), 'an admin may');
        self::assertFalse(SupportService::mayEdit($ticket, $fromPlayer, $admin), 'never what the player wrote');
        self::assertFalse(SupportService::mayEdit($ticket, $fromModerator, $player), 'players edit nothing of the team');
        $this->expectException(GameException::class);
        $support->editTeamMessage($ticket, $fromPlayer, $admin, 'umgeschrieben');
    }

    public function testThePlayersOwnReportStaysHisOwn(): void
    {
        $author = new User('melder');
        $admin = new User('Chefin');
        $admin->setRoles(['ROLE_ADMIN']);
        $ticket = $this->support([$admin])->create($author, TicketKind::Suggestion, 'Idee', 'Mehr Planeten');

        self::assertFalse(SupportService::mayEdit($ticket, null, $admin), 'the opening message is the player\'s');
    }

    public function testPlayersCannotOpenAMultiCheckThemselves(): void
    {
        $this->expectException(GameException::class);

        $this->support([])->create(new User('spieler'), TicketKind::MultiCheck, 'Hallo', 'Text');
    }

    /** @param list<User> $team */
    private function support(array $team): SupportService
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        $users = $this->createStub(UserRepository::class);
        $users->method('findAll')->willReturn($team);
        $clock = new MockClock('2026-11-15 12:00:00');
        $inbox = new Inbox($em, $clock);
        $planets = new PlanetService($em, new LockFactory(new InMemoryStore()), $clock, new PlanetUpdater(new Economy(1.0)), new Economy(1.0));
        $vacations = new VacationService($em, $planets, $this->createStub(FleetRepository::class), $users, $inbox, new LockFactory(new InMemoryStore()), $clock);

        return new SupportService($em, $users, $inbox, $vacations, $this->createStub(MailerInterface::class), $clock);
    }

    /** @return list<Message> */
    private function messages(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Message));
    }
}
