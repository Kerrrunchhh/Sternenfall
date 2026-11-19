<?php

namespace App\Tests\Game;

use App\Entity\Message;
use App\Entity\User;
use App\Game\Inbox;
use App\Game\MessageFolder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

use function Symfony\Component\Translation\t;

/** System messages are sorted into folders: battle reports, espionage, general. */
final class MessageFolderTest extends TestCase
{
    public function testMessagesGoIntoTheFolderTheyAreSentToGeneralByDefault(): void
    {
        $now = new \DateTimeImmutable('2026-11-19 12:00');
        $player = new User('leser', $now);
        $stored = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $message) use (&$stored): void {
            $stored[] = $message;
        });
        $inbox = new Inbox($em, new MockClock($now));

        $inbox->send($player, t('Transport angekommen'), t('Alles da.'));
        $inbox->send($player, t('Spionagebericht'), t('Viel Eisen.'), folder: MessageFolder::Spy);
        $inbox->send($player, t('Kampfbericht'), t('Gewonnen.'), folder: MessageFolder::Battle);

        self::assertSame([MessageFolder::General, MessageFolder::Spy, MessageFolder::Battle], array_map(static fn (Message $m): MessageFolder => $m->getFolder(), $stored));
    }

    public function testEveryFolderHasALabelAndAnIcon(): void
    {
        foreach (MessageFolder::cases() as $folder) {
            self::assertNotSame('', $folder->label());
            self::assertNotSame('', $folder->icon());
        }
    }
}
