<?php

namespace App\Game;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Stores uploaded pictures for player profiles and alliances.
 *
 * Every upload is validated, decoded and re-encoded with GD (max 400×400, WebP):
 * that strips metadata and anything hidden in the original file. Files live
 * outside the web root and are delivered by MediaController.
 */
final readonly class ImageStore
{
    public const array KINDS = ['avatars', 'alliances'];
    public const int MAX_SIZE = 400;

    public function __construct(
        private ValidatorInterface $validator,
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $directory,
    ) {
    }

    /** @return string the new file name */
    public function store(UploadedFile $file, string $kind): string
    {
        $this->assertKind($kind);
        $violations = $this->validator->validate($file, new Image(
            maxSize: '2M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            maxWidth: 6000,
            maxHeight: 6000,
            maxSizeMessage: 'Das Bild darf höchstens 2 MB groß sein.',
            mimeTypesMessage: 'Bitte lade ein JPEG-, PNG-, WebP- oder GIF-Bild hoch.',
        ));
        if (\count($violations) > 0) {
            throw new GameException((string) $violations->get(0)->getMessage());
        }

        $source = @imagecreatefromstring((string) file_get_contents($file->getPathname()));
        if (false === $source) {
            throw new GameException(t('Das Bild konnte nicht gelesen werden.'));
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, self::MAX_SIZE / max($width, $height));
        $target = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, imagesx($target), imagesy($target), $width, $height);

        $name = bin2hex(random_bytes(16)).'.webp';
        $dir = $this->directory.'/'.$kind;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Upload directory can not be created.');
        }
        if (!imagewebp($target, $dir.'/'.$name, 85)) {
            throw new \RuntimeException('Image could not be written.');
        }

        return $name;
    }

    public function delete(?string $name, string $kind): void
    {
        if (null !== $name && null !== $path = $this->path($name, $kind)) {
            @unlink($path);
        }
    }

    /** Absolute path of a stored file, or null if the name is not one of ours. */
    public function path(string $name, string $kind): ?string
    {
        $this->assertKind($kind);
        if (!preg_match('/^[a-f0-9]{32}\.webp$/', $name)) {
            return null;
        }
        $path = $this->directory.'/'.$kind.'/'.$name;

        return is_file($path) ? $path : null;
    }

    private function assertKind(string $kind): void
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException("Unknown image kind $kind");
        }
    }
}
