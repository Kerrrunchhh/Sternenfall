<?php

namespace App\Game;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A player's public profile: picture and text, shown when others click the name.
 */
final readonly class ProfileService
{
    public const int TEXT_LIMIT = 5000;

    public function __construct(
        private EntityManagerInterface $em,
        private ImageStore $images,
    ) {
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
