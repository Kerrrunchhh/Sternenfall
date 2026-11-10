<?php

namespace App\Controller;

use App\Game\MultiAccounts;
use App\Game\PushingGuard;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Possible multi accounts and pushing for moderators and admins – nothing is blocked automatically. */
#[IsGranted('ROLE_MODERATOR')]
class AdminMultiController extends AbstractGameController
{
    #[Route('/admin/multis', name: 'app_admin_multis', methods: ['GET'])]
    public function index(MultiAccounts $multis): Response
    {
        return $this->renderGame('game/admin_multis.html.twig', $this->currentPlanet(), [
            'pairs' => $multis->suspiciousPairs(),
            'gifts' => $multis->biggestGifts(),
            'keep_days' => MultiAccounts::KEEP_DAYS,
            'push_share' => (int) round(100 * PushingGuard::SHARE_OF_WEEKLY_PRODUCTION),
        ]);
    }
}
