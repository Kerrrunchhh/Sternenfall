<?php

namespace App\Controller;

use App\Game\GameStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Public pages: status of the universe and the legal notice. */
class StatusController extends AbstractController
{
    #[Route('/status', name: 'app_status', methods: ['GET'])]
    public function status(GameStats $stats): Response
    {
        return $this->render('status.html.twig', ['stats' => $stats->status(), 'top' => $stats->topExperienceToday()]);
    }

    #[Route('/impressum', name: 'app_imprint', methods: ['GET'])]
    public function imprint(): Response
    {
        return $this->render('imprint.html.twig');
    }
}
