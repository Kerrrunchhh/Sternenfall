<?php

namespace App\Controller;

use App\I18n\Locales;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** For search engines: what they may crawl (robots.txt) and the public pages (sitemap.xml). */
class SeoController extends AbstractController
{
    /** Public pages worth finding, with how often they change. */
    public const array PAGES = [
        'app_home' => 'weekly',
        'app_register' => 'monthly',
        'app_status' => 'daily',
        'app_terms' => 'yearly',
        'app_imprint' => 'yearly',
        'app_privacy' => 'yearly',
    ];

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(): Response
    {
        // Only the public pages; the game itself needs a login, shared combat reports expire.
        $allowed = array_map(fn (string $route): string => 'Allow: '.$this->generateUrl($route).('/' === $this->generateUrl($route) ? '$' : ''), array_keys(self::PAGES));
        $lines = [
            'User-agent: *',
            ...$allowed,
            'Allow: /css/',
            'Allow: /js/',
            'Allow: /pictures/',
            'Allow: /ads.txt',
            'Disallow: /',
            '',
            'Sitemap: '.$this->generateUrl('app_sitemap', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        return new Response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(Locales $locales): Response
    {
        $response = $this->render('sitemap.xml.twig', [
            'pages' => self::PAGES,
            'locales' => $locales->all(),
            'default_locale' => $locales->default(),
        ]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }
}
