<?php

namespace App\Controller;

use App\Entity\BattleReport;
use App\Repository\BattleReportRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** The newspaper: every combat report of the game, for everybody, but only 24 hours later. */
class NewspaperController extends AbstractGameController
{
    public const int DELAY_HOURS = 24;
    private const int PER_PAGE = 20;

    #[Route('/zeitung', name: 'app_newspaper', methods: ['GET'])]
    public function index(Request $request, BattleReportRepository $reports): Response
    {
        $planet = $this->currentPlanet();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $until = $now->modify('-'.self::DELAY_HOURS.' hours');
        $from = $now->modify('-'.BattleReport::DAYS_AVAILABLE.' days');

        $total = $reports->countPublished($from, $until);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, ctype_digit($p = $request->query->getString('page')) ? (int) $p : 1));

        return $this->renderGame('game/newspaper.html.twig', $planet, [
            'reports' => $reports->findPublished($from, $until, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'delay' => self::DELAY_HOURS,
            'days' => BattleReport::DAYS_AVAILABLE,
        ]);
    }
}
