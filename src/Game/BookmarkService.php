<?php

namespace App\Game;

use App\Entity\Bookmark;
use App\Entity\User;
use App\Repository\BookmarkRepository;
use App\Repository\PlanetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function Symfony\Component\Translation\t;

/** Remembered coordinates of a player ("Koordinaten merken"). */
final readonly class BookmarkService
{
    public const int MAX_BOOKMARKS = 200;

    public function __construct(
        private EntityManagerInterface $em,
        private BookmarkRepository $bookmarks,
        private PlanetRepository $planets,
        private ClockInterface $clock,
    ) {
    }

    /** Remembers a slot; the label defaults to the planet there (or "Platz g:s:p"). Returns the bookmark. */
    public function add(User $player, int $galaxy, int $system, int $position): Bookmark
    {
        if (!Universe::isValidSlot($galaxy, $system, $position)) {
            throw new GameException(t('Diese Koordinaten gibt es nicht.'));
        }
        $existing = $this->bookmarks->findOneAt($player, $galaxy, $system, $position);
        if (null !== $existing) {
            return $existing;
        }
        $all = $this->bookmarks->findByOwner($player);
        if (\count($all) >= self::MAX_BOOKMARKS) {
            throw new GameException(t('Du kannst dir höchstens {value} Koordinaten merken. Lösche zuerst alte.', ['{value}' => self::MAX_BOOKMARKS]));
        }
        $planet = $this->planets->findAt($galaxy, $system, $position);
        $label = null !== $planet
            ? mb_substr(\sprintf('%s (%s)', $planet->getName(), $planet->getOwner()->getUsername()), 0, 40)
            : \sprintf('Platz %d:%d:%d', $galaxy, $system, $position);

        $bookmark = new Bookmark($player, $galaxy, $system, $position, $label, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $bookmark->setSortOrder(array_reduce($all, static fn (int $max, Bookmark $b): int => max($max, $b->getSortOrder()), 0) + 1);
        $this->em->persist($bookmark);
        $this->em->flush();

        return $bookmark;
    }

    public function rename(Bookmark $bookmark, User $player, string $label): void
    {
        $this->assertOwner($bookmark, $player);
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? '');
        if ('' === $label || mb_strlen($label) > 40) {
            throw new GameException(t('Der Name muss 1 bis 40 Zeichen lang sein.'));
        }
        $bookmark->setLabel($label);
        $this->em->flush();
    }

    public function remove(Bookmark $bookmark, User $player): void
    {
        $this->assertOwner($bookmark, $player);
        $this->em->remove($bookmark);
        $this->em->flush();
    }

    /**
     * Moves the selected bookmarks one place or all the way up or down. Several selected ones
     * keep their order among each other; a block that already is at the edge stays there.
     *
     * @param list<int> $ids
     */
    public function move(User $player, array $ids, BookmarkMove $move): void
    {
        $list = $this->bookmarks->findByOwner($player);
        $selected = array_fill_keys($ids, true);
        $isSelected = static fn (Bookmark $b): bool => isset($selected[$b->getId()]);
        if ([] === array_filter($list, $isSelected)) {
            throw new GameException(t('Wähle zuerst Koordinaten aus.'));
        }

        switch ($move) {
            case BookmarkMove::Top:
            case BookmarkMove::Bottom:
                $picked = array_values(array_filter($list, $isSelected));
                $rest = array_values(array_filter($list, static fn (Bookmark $b): bool => !$isSelected($b)));
                $list = BookmarkMove::Top === $move ? [...$picked, ...$rest] : [...$rest, ...$picked];
                break;
            case BookmarkMove::Up:
                for ($i = 1, $n = \count($list); $i < $n; ++$i) {
                    if ($isSelected($list[$i]) && !$isSelected($list[$i - 1])) {
                        [$list[$i - 1], $list[$i]] = [$list[$i], $list[$i - 1]];
                    }
                }
                break;
            case BookmarkMove::Down:
                for ($i = \count($list) - 2; $i >= 0; --$i) {
                    if ($isSelected($list[$i]) && !$isSelected($list[$i + 1])) {
                        [$list[$i + 1], $list[$i]] = [$list[$i], $list[$i + 1]];
                    }
                }
                break;
        }

        $this->renumber($list);
    }

    /**
     * Takes over a complete order (drag & drop). Unknown ids are ignored; bookmarks missing
     * from it keep their order behind the given ones.
     *
     * @param list<int> $ids
     */
    public function reorder(User $player, array $ids): void
    {
        $byId = [];
        foreach ($this->bookmarks->findByOwner($player) as $bookmark) {
            $byId[$bookmark->getId()] = $bookmark;
        }
        $list = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $list[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        $this->renumber([...$list, ...array_values($byId)]);
    }

    /** @param list<Bookmark> $list */
    private function renumber(array $list): void
    {
        foreach ($list as $i => $bookmark) {
            $bookmark->setSortOrder($i + 1);
        }
        $this->em->flush();
    }

    private function assertOwner(Bookmark $bookmark, User $player): void
    {
        if (!$bookmark->getOwner()->isSameAs($player)) {
            throw new GameException(t('Diese Koordinaten hast du dir nicht gemerkt.'));
        }
    }
}
