<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** /ads.txt: authorizes Google AdSense to sell ads on this domain (from ADSENSE_CLIENT). */
class AdsTxtController extends AbstractController
{
    #[Route('/ads.txt', name: 'app_ads_txt', methods: ['GET'])]
    public function __invoke(#[Autowire(env: 'ADSENSE_CLIENT')] string $client): Response
    {
        $publisher = preg_replace('/^ca-/', '', trim($client)) ?? '';
        if (1 !== preg_match('/^pub-\d{10,20}$/', $publisher)) {
            throw $this->createNotFoundException();
        }

        return new Response("google.com, {$publisher}, DIRECT, f08c47fec0942fa0\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
