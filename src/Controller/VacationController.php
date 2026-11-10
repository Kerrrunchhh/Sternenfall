<?php

namespace App\Controller;

use App\Entity\User;
use App\Game\GameException;
use App\Game\VacationService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The only page a player on vacation sees. */
#[IsGranted('ROLE_USER')]
class VacationController extends AbstractController
{
    #[Route('/urlaub', name: 'app_vacation', methods: ['GET'])]
    public function show(ClockInterface $clock): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $now = \DateTimeImmutable::createFromInterface($clock->now());
        if (!$user->isOnVacation($now)) {
            return $this->redirectToRoute('app_overview');
        }

        return $this->render('vacation.html.twig', [
            'player' => $user,
            'now' => $now,
            'can_end_at' => $user->isVacationForced() ? null : $user->getVacationSince()->modify('+1 day'),
        ]);
    }

    #[Route('/urlaub/beenden', name: 'app_vacation_end', methods: ['POST'])]
    #[IsCsrfTokenValid('vacation', tokenKey: '_token')]
    public function end(VacationService $vacations): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        try {
            $vacations->endEarly($user);
            $this->addFlash('success', 'Willkommen zurück! Dein Urlaub ist beendet, alles läuft weiter.');

            return $this->redirectToRoute('app_overview');
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_vacation');
        }
    }
}
