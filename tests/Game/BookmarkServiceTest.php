<?php

namespace App\Tests\Game;

use App\Entity\Bookmark;
use App\Entity\Planet;
use App\Entity\User;
use App\Game\BookmarkMove;
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

    public function testNewBookmarksGoToTheEndOfTheList(): void
    {
        $list = $this->list(3);

        self::assertSame(4, $this->service(list: $list)->add($this->player, 3, 146, 15)->getSortOrder());
    }

    /** @return iterable<string, array{BookmarkMove, list<int>, string}> */
    public static function moves(): iterable
    {
        yield 'up keeps the order of the selection' => [BookmarkMove::Up, [2, 4], '2 1 4 3 5'];
        yield 'up stops at the top' => [BookmarkMove::Up, [1, 3], '1 3 2 4 5'];
        yield 'a block moves up together' => [BookmarkMove::Up, [3, 4], '1 3 4 2 5'];
        yield 'down keeps the order of the selection' => [BookmarkMove::Down, [2, 4], '1 3 2 5 4'];
        yield 'down stops at the bottom' => [BookmarkMove::Down, [3, 5], '1 2 4 3 5'];
        yield 'top' => [BookmarkMove::Top, [4, 2], '2 4 1 3 5'];
        yield 'bottom' => [BookmarkMove::Bottom, [1, 3], '2 4 5 1 3'];
        yield 'ids of others are ignored' => [BookmarkMove::Top, [99, 5], '5 1 2 3 4'];
    }

    /** @param list<int> $ids */
    #[\PHPUnit\Framework\Attributes\DataProvider('moves')]
    public function testMoveSelected(BookmarkMove $move, array $ids, string $expected): void
    {
        $list = $this->list(5);

        $this->service(list: $list)->move($this->player, $ids, $move);

        self::assertSame($expected, $this->order($list));
    }

    public function testMovingWithoutSelectionIsRejected(): void
    {
        $this->expectException(GameException::class);
        $this->service(list: $this->list(3))->move($this->player, [42], BookmarkMove::Up);
    }

    public function testReorderTakesTheWholeOrderAndKeepsForgottenOnesBehind(): void
    {
        $list = $this->list(5);

        $this->service(list: $list)->reorder($this->player, [4, 99, 2, 4, 5]);

        self::assertSame('4 2 5 1 3', $this->order($list));
    }

    /** @return list<Bookmark> bookmarks with ids 1..n in this order */
    private function list(int $count): array
    {
        $list = [];
        for ($i = 1; $i <= $count; ++$i) {
            $bookmark = new Bookmark($this->player, 3, 146, $i, 'B'.$i, $this->now);
            new \ReflectionProperty(Bookmark::class, 'id')->setValue($bookmark, $i);
            $bookmark->setSortOrder($i);
            $list[] = $bookmark;
        }

        return $list;
    }

    /** @param list<Bookmark> $list */
    private function order(array $list): string
    {
        usort($list, static fn (Bookmark $a, Bookmark $b): int => $a->getSortOrder() <=> $b->getSortOrder());

        return implode(' ', array_map(static fn (Bookmark $b): ?int => $b->getId(), $list));
    }

    /** @param list<Bookmark> $list */
    private function service(?Planet $planet = null, ?Bookmark $existing = null, array $list = []): BookmarkService
    {
        $bookmarks = $this->createStub(BookmarkRepository::class);
        $bookmarks->method('findOneAt')->willReturn($existing);
        $bookmarks->method('findByOwner')->willReturn($list);
        $planets = $this->createStub(PlanetRepository::class);
        $planets->method('findAt')->willReturn($planet);
        return new BookmarkService($this->createStub(EntityManagerInterface::class), $bookmarks, $planets, new MockClock($this->now));
    }
}
