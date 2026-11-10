<?php

namespace App\Controller;

use App\Game\LifeForm;
use App\Game\LifeFormService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

class LifeFormController extends AbstractGameController
{
    #[Route('/lifeform', name: 'app_lifeform', methods: ['GET'])]
    public function index(LifeFormService $lifeForms): Response
    {
        $planet = $this->currentPlanet();
        $player = $planet->getOwner();

        return $this->renderGame('game/lifeform.html.twig', $planet, [
            'life_forms' => LifeForm::cases(),
            'posts_lost' => $lifeForms->postsLostOnChange($planet),
            'can_change' => $player->getNextLifeFormChangeAt() <= $this->clock->now(),
        ]);
    }

    #[Route('/lifeform/{lifeForm}', name: 'app_lifeform_change', requirements: ['lifeForm' => new EnumRequirement(LifeForm::class)], methods: ['POST'])]
    #[IsCsrfTokenValid('lifeform', tokenKey: '_token')]
    public function change(LifeForm $lifeForm, LifeFormService $lifeForms): Response
    {
        $planet = $this->currentPlanet();
        $this->attempt(fn () => $lifeForms->change($planet, $lifeForm), t('Deine Lebensform ist jetzt: {lifeForm}.', ['{lifeForm}' => t($lifeForm->label())]));

        return $this->redirectToRoute('app_lifeform');
    }
}
