<?php

namespace App\Controller;

use App\Entity\User;
use App\Game\ProfileService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class ProfileController extends AbstractGameController
{
    /** Anyone's profile; the own one can be edited here. */
    #[Route('/player/{id}', name: 'app_player', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(User $user): Response
    {
        $planet = $this->currentPlanet();

        return $this->renderGame('game/player.html.twig', $planet, [
            'profile' => $user,
            'own' => $user->isSameAs($this->player()),
        ]);
    }

    #[Route('/profile', name: 'app_profile_update', methods: ['POST'])]
    #[IsCsrfTokenValid('profile', tokenKey: '_token')]
    public function update(Request $request, ProfileService $profiles): Response
    {
        $this->currentPlanet();
        $this->attempt(
            fn () => $profiles->update($this->player(), $request->request->getString('text'), $request->files->get('image'), $request->request->getBoolean('remove_image')),
            'Profil gespeichert.',
        );

        return $this->redirectToRoute('app_player', ['id' => $this->player()->getId()]);
    }
}
