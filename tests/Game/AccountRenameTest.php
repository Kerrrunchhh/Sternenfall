<?php

namespace App\Tests\Game;

use App\Entity\Message;
use App\Entity\User;
use App\Game\AccountRename;
use App\Game\GameException;
use App\Game\Inbox;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Component\Validator\Validation;

/** An admin renames an account – only to a free name that follows the sign-up rules. */
class AccountRenameTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testTheNameChangesAndThePlayerIsTold(): void
    {
        $player = new User('Alt_Name');

        self::assertSame('Alt_Name', $this->rename([$player])->rename($player, ' Neuer-Name '));

        self::assertSame('Neuer-Name', $player->getUsername());
        $message = array_values(array_filter($this->persisted, static fn (object $o): bool => $o instanceof Message))[0];
        self::assertStringContainsString('Alt_Name', $message->getBody());
        self::assertStringContainsString('Neuer-Name', $message->getBody());
    }

    public function testATakenNameIsRefusedAlsoInOtherCase(): void
    {
        $player = new User('Spieler');
        $anna = new User('Anna');
        (new \ReflectionProperty(User::class, 'id'))->setValue($player, 1);
        (new \ReflectionProperty(User::class, 'id'))->setValue($anna, 2);

        $this->assertRefused($this->rename([$player, $anna]), $player, 'anna', 'hat schon Anna');
        self::assertSame('Spieler', $player->getUsername());
        // His own name in other case is fine.
        $this->rename([$player, $anna])->rename($player, 'SPIELER');
        self::assertSame('SPIELER', $player->getUsername());
    }

    public function testTheSignUpRulesAndSystemNamesApply(): void
    {
        $player = new User('Spieler');
        $rename = $this->rename([$player]);

        $this->assertRefused($rename, $player, 'ab', 'Ungültiger Name');
        $this->assertRefused($rename, $player, 'mit Leerzeichen', 'Ungültiger Name');
        $this->assertRefused($rename, $player, str_repeat('x', 31), 'Ungültiger Name');
        $this->assertRefused($rename, $player, 'handelsgilde', 'Systemkonto');
        $this->assertRefused($rename, $player, 'Spieler', 'schon sein Name');
    }

    /** @param list<User> $all */
    private function rename(array $all): AccountRename
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persisted[] = $o;
        });
        $users = $this->createStub(UserRepository::class);
        $users->method('findOneByNameIgnoringCase')->willReturnCallback(static function (string $name) use ($all): ?User {
            foreach ($all as $u) {
                if (0 === strcasecmp($u->getUsername(), $name)) {
                    return $u;
                }
            }

            return null;
        });
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        return new AccountRename($users, $em, $validator, new Inbox($em, new MockClock()));
    }

    private function assertRefused(AccountRename $rename, User $user, string $name, string $reason): void
    {
        try {
            $rename->rename($user, $name);
            self::fail('expected a refusal for '.$name);
        } catch (GameException $e) {
            self::assertStringContainsString($reason, $e->translatable()->trans(new IdentityTranslator()));
        }
    }
}
