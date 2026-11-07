<?php

namespace App\Controller;

use App\Game\ImageStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Delivers uploaded pictures (stored outside the web root) to logged-in players.
 */
#[IsGranted('ROLE_USER')]
class MediaController extends AbstractController
{
    #[Route('/media/{kind}/{name}', name: 'app_media', requirements: ['kind' => 'avatars|alliances', 'name' => '[a-f0-9]{32}\.webp'], methods: ['GET'])]
    public function show(string $kind, string $name, ImageStore $images): Response
    {
        $path = $images->path($name, $kind) ?? throw $this->createNotFoundException();

        $response = new BinaryFileResponse($path, headers: ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff']);
        // File names are random and never reused, so the browser may keep them for good.
        $response->setPrivate();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }
}
