<?php

namespace App\Controller;

use App\Game\GameException;
use App\Game\VacationService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** What a player on vacation sees instead of the pages he can't use right now. */
class VacationController extends AbstractGameController
{
    #[Route('/urlaub', name: 'app_vacation', methods: ['GET'])]
    public function show(): Response
    {
        $planet = $this->currentPlanet();
        $user = $this->player();
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        if (!$user->isOnVacation($now)) {
            return $this->redirectToRoute('app_overview');
        }

        return $this->renderGame('game/vacation.html.twig', $planet, [
            'can_end_at' => $user->isVacationForced() ? null : $user->getVacationSince()->modify('+1 day'),
        ]);
    }

    #[Route('/urlaub/beenden', name: 'app_vacation_end', methods: ['POST'])]
    #[IsCsrfTokenValid('vacation', tokenKey: '_token')]
    public function end(VacationService $vacations): Response
    {
        $this->currentPlanet();
        try {
            $vacations->endEarly($this->player());
            $this->addFlash('success', t('Willkommen zurück! Dein Urlaub ist beendet, alles läuft weiter.'));

            return $this->redirectToRoute('app_overview');
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());

            return $this->redirectToRoute('app_vacation');
        }
    }
}
