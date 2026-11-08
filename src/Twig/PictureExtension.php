<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/** Pictures of buildings, ships and defenses under public/pictures/<kind>/<value>.webp. */
final class PictureExtension
{
    /** @var array<string, bool> */
    private array $exists = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDir,
    ) {
    }

    /** Public path of the picture (for asset()), or null while there is none. */
    #[AsTwigFunction('game_picture')]
    public function path(string $kind, string $value): ?string
    {
        $path = \sprintf('pictures/%s/%s.webp', $kind, $value);

        return ($this->exists[$path] ??= is_file($this->publicDir.'/'.$path)) ? $path : null;
    }
}
