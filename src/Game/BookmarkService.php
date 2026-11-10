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
        if (\count($this->bookmarks->findByOwner($player)) >= self::MAX_BOOKMARKS) {
            throw new GameException(t('Du kannst dir höchstens {value} Koordinaten merken. Lösche zuerst alte.', ['{value}' => self::MAX_BOOKMARKS]));
        }
        $planet = $this->planets->findAt($galaxy, $system, $position);
        $label = null !== $planet
            ? mb_substr(\sprintf('%s (%s)', $planet->getName(), $planet->getOwner()->getUsername()), 0, 40)
            : \sprintf('Platz %d:%d:%d', $galaxy, $system, $position);

        $bookmark = new Bookmark($player, $galaxy, $system, $position, $label, \DateTimeImmutable::createFromInterface($this->clock->now()));
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

    private function assertOwner(Bookmark $bookmark, User $player): void
    {
        if (!$bookmark->getOwner()->isSameAs($player)) {
            throw new GameException(t('Diese Koordinaten hast du dir nicht gemerkt.'));
        }
    }
}
