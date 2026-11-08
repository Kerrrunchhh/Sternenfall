<?php

namespace App\Tests\Game;

use App\Entity\Bookmark;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\BookmarkService;
use App\Game\GameException;
use App\Repository\BookmarkRepository;
use App\Repository\PlanetRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

class BookmarkServiceTest extends TestCase
{
    private \DateTimeImmutable $now;
    private User $player;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01');
        $this->player = new User('navigator', $this->now);
    }

    public function testLabelDefaultsToThePlanetAndItsOwner(): void
    {
        $planet = new Planet(new User('merkur', $this->now), 'Kontor', 3, 146, 7, $this->now);

        $bookmark = $this->service(planet: $planet)->add($this->player, 3, 146, 7);

        self::assertSame('Kontor (merkur)', $bookmark->getLabel());
        self::assertSame('3:146:7', $bookmark->getCoordinates());
    }

    public function testFreeSlotGetsItsCoordinatesAsLabel(): void
    {
        self::assertSame('Platz 3:146:15', $this->service()->add($this->player, 3, 146, 15)->getLabel());
    }

    public function testRememberingTwiceKeepsOneBookmark(): void
    {
        $existing = new Bookmark($this->player, 3, 146, 15, 'Sammelpunkt', $this->now);

        self::assertSame($existing, $this->service(existing: $existing)->add($this->player, 3, 146, 15));
    }

    public function testRenameChecksOwnerAndLength(): void
    {
        $bookmark = new Bookmark($this->player, 3, 146, 15, 'Alt', $this->now);
        $service = $this->service();

        $service->rename($bookmark, $this->player, '  Sammelpunkt   Ost ');
        self::assertSame('Sammelpunkt Ost', $bookmark->getLabel());

        foreach ([[$this->player, str_repeat('x', 41)], [new User('fremd', $this->now), 'Meins']] as [$actor, $label]) {
            try {
                $service->rename($bookmark, $actor, $label);
                self::fail('should be rejected');
            } catch (GameException) {
                self::assertSame('Sammelpunkt Ost', $bookmark->getLabel());
            }
        }
    }

    public function testUnknownCoordinatesAreRejected(): void
    {
        $this->expectException(GameException::class);
        $this->service()->add($this->player, 3, 146, 99);
    }

    private function service(?Planet $planet = null, ?Bookmark $existing = null): BookmarkService
    {
        $bookmarks = $this->createStub(BookmarkRepository::class);
        $bookmarks->method('findOneAt')->willReturn($existing);
        $bookmarks->method('findByOwner')->willReturn([]);
        $planets = $this->createStub(PlanetRepository::class);
        $planets->method('findAt')->willReturn($planet);
        return new BookmarkService($this->createStub(EntityManagerInterface::class), $bookmarks, $planets, new MockClock($this->now));
    }
}
