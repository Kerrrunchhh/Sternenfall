<?php

namespace App\Tests\Game;

use App\Entity\Message;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\CreditTransfer;
use App\Game\Economy;
use App\Game\GameException;
use App\Game\Inbox;
use App\Game\PlanetService;
use App\Game\PlanetUpdater;
use App\Game\PushingGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** A player pays another one credits, with a message; the receiver gets them in one statement. */
class CreditTransferTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];
    /** @var list<array{string, list<mixed>}> */
    private array $statements = [];
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-17 10:00:00');
    }

    public function testThePayerPaysAndTheReceiverIsToldWithTheMessage(): void
    {
        [$payer, $planet] = $this->player('zahler', 1, 1000);
        [$receiver] = $this->player('empfaenger', 2, 0);
        // The receiver is the smaller one here: no pushing limit.
        $this->transfer()->pay($planet, $receiver, 300, '  Danke für die Hilfe!  ');

        self::assertSame(700.0, $payer->getCredits());
        self::assertSame([[300, 2]], array_map(static fn (array $s): array => $s[1], $this->statements), 'credited in one statement');
        $message = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Message))[0];
        self::assertSame($receiver, $message->getRecipient());
        self::assertStringContainsString('zahler', $message->getSubject());
        self::assertStringContainsString('Danke für die Hilfe!', $message->getBody());
    }

    public function testWhatIsRefused(): void
    {
        [$payer, $planet] = $this->player('zahler', 1, 100);
        [$receiver] = $this->player('empfaenger', 2, 0);
        $system = new User('Handelsgilde');
        $system->markSystem();
        $transfer = $this->transfer();

        foreach ([[$receiver, 101, 'more than he has'], [$receiver, 0, 'nothing'], [$payer, 10, 'himself'], [$system, 10, 'a system account']] as [$to, $amount, $why]) {
            try {
                $transfer->pay($planet, $to, $amount, '');
                self::fail('expected a refusal: '.$why);
            } catch (GameException) {
                $this->addToAssertionCount(1);
            }
        }
        self::assertSame(100.0, $payer->getCredits(), 'nothing paid');
        self::assertSame([], $this->statements);
    }

    /** @return array{User, Planet} */
    private function player(string $name, int $id, float $credits): array
    {
        $user = new User($name, $this->now);
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->addCredits($credits);
        $planet = new Planet($user, 'Heim', 1, $id, 1, $this->now);

        return [$user, $planet];
    }

    private function transfer(): CreditTransfer
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('executeStatement')->willReturnCallback(function (string $sql, array $params): int {
            $this->statements[] = [$sql, $params];

            return 1;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        $clock = new MockClock($this->now);
        $economy = new Economy(1.0);
        $planets = new PlanetService($em, new LockFactory(new InMemoryStore()), $clock, new PlanetUpdater($economy), $economy);

        return new CreditTransfer($planets, new PushingGuard($em, $economy), new Inbox($em, $clock), $em);
    }
}
