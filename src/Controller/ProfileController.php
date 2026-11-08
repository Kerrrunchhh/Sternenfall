<?php

namespace App\Controller;

use App\Entity\Planet;
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

        $own = $user->isSameAs($this->player());

        return $this->renderGame('game/player.html.twig', $planet, [
            'profile' => $user,
            'own' => $own,
            'listed_planets' => ProfileService::listedPlanets($user, $own),
            'hidden_count' => \count(ProfileService::hiddenPlanets($user)),
            'max_hidden' => ProfileService::MAX_HIDDEN_PLANETS,
        ]);
    }

    #[Route('/profile/planets/{id}/visibility', name: 'app_profile_planet_visibility', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('profile', tokenKey: '_token')]
    public function planetVisibility(Planet $planet, Request $request, ProfileService $profiles): Response
    {
        $this->currentPlanet();
        $hidden = $request->request->getBoolean('hidden');
        $this->attempt(
            fn () => $profiles->setPlanetHidden($this->player(), $planet, $hidden),
            \sprintf('%s wird in deinem Profil %s.', $planet->getName(), $hidden ? 'nicht mehr angezeigt' : 'wieder angezeigt'),
        );

        return $this->redirectToRoute('app_player', ['id' => $this->player()->getId()]);
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
