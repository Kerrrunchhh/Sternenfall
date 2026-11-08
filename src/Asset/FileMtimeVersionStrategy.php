<?php

namespace App\Asset;

use Symfony\Component\Asset\VersionStrategy\VersionStrategyInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Appends the file's modification time (?v=1731060000), so browsers fetch
 * changed CSS and JavaScript at once instead of using a stale cached copy.
 */
final readonly class FileMtimeVersionStrategy implements VersionStrategyInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDir,
    ) {
    }

    public function getVersion(string $path): string
    {
        $file = $this->publicDir.'/'.ltrim($path, '/');

        return is_file($file) ? (string) filemtime($file) : '';
    }

    public function applyVersion(string $path): string
    {
        $version = $this->getVersion($path);

        return '' === $version ? $path : $path.'?v='.$version;
    }
}
