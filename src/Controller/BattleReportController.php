<?php

namespace App\Controller;

use App\Entity\BattleReport;
use App\Game\BattleReportRefunds;
use App\Game\BattleReports;
use App\Game\ShipType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Shared combat reports: anyone with the link can read them for 30 days, even without logging in. */
class BattleReportController extends AbstractController
{
    #[Route('/kampfbericht/{hash}', name: 'app_battle_report', requirements: ['hash' => '[0-9a-f]{24}'], methods: ['GET'])]
    public function show(string $hash, BattleReports $reports, BattleReportRefunds $refunds): Response
    {
        $report = $reports->find($hash);

        return $this->render('battle_report.html.twig', [
            'report' => $report,
            'days' => BattleReport::DAYS_AVAILABLE,
            // Admins can annul the report and give ships back.
            'refund_players' => null !== $report && $this->isGranted('ROLE_ADMIN') ? $refunds->participants($report) : null,
            'ship_types' => ShipType::cases(),
        ], new Response(status: null === $report ? Response::HTTP_NOT_FOUND : Response::HTTP_OK));
    }
}
