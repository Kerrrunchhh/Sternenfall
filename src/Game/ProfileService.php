<?php

namespace App\Game;

use App\Entity\Planet;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use function Symfony\Component\Translation\t;

/**
 * A player's public profile: picture and text, shown when others click the name.
 */
final readonly class ProfileService
{
    public const int TEXT_LIMIT = 5000;
    /** Planets a player may leave out of the planet list on his profile. */
    public const int MAX_HIDDEN_PLANETS = 3;

    public function __construct(
        private EntityManagerInterface $em,
        private ImageStore $images,
    ) {
    }

    /** Hides a planet from (or shows it again in) the public planet list of its owner's profile. */
    public function setPlanetHidden(User $player, Planet $planet, bool $hidden): void
    {
        if (!$planet->getOwner()->isSameAs($player)) {
            throw new GameException(t('Das ist nicht dein Planet.'));
        }
        if ($hidden && !$planet->isHiddenInProfile() && \count(self::hiddenPlanets($player)) >= self::MAX_HIDDEN_PLANETS) {
            throw new GameException(t('Du kannst höchstens {value} Planeten verbergen. Zeige zuerst einen anderen wieder an.', ['{value}' => self::MAX_HIDDEN_PLANETS]));
        }
        $planet->setHiddenInProfile($hidden);
        $this->em->flush();
    }

    /** @return list<Planet> */
    public static function hiddenPlanets(User $player): array
    {
        return array_values(array_filter($player->getPlanets()->toArray(), static fn (Planet $p): bool => $p->isHiddenInProfile()));
    }

    /**
     * Planets others see on the profile (the owner sees all of them).
     *
     * @return list<Planet>
     */
    public static function listedPlanets(User $player, bool $own): array
    {
        $planets = array_values(array_filter($player->getPlanets()->toArray(), static fn (Planet $p): bool => $own || !$p->isHiddenInProfile()));
        usort($planets, static fn (Planet $a, Planet $b): int => [$a->getGalaxy(), $a->getSystem(), $a->getPosition()] <=> [$b->getGalaxy(), $b->getSystem(), $b->getPosition()]);

        return $planets;
    }

    public function update(User $player, string $text, ?UploadedFile $image, bool $removeImage): void
    {
        $player->setProfileText(mb_substr(trim($text), 0, self::TEXT_LIMIT));

        if (null !== $image) {
            $stored = $this->images->store($image, 'avatars');
            $this->images->delete($player->getAvatar(), 'avatars');
            $player->setAvatar($stored);
        } elseif ($removeImage) {
            $this->images->delete($player->getAvatar(), 'avatars');
            $player->setAvatar(null);
        }
        $this->em->flush();
    }
}
