<?php

namespace App\Controller;

use App\Game\Economy;
use App\Game\ResearchType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

class ResearchController extends AbstractGameController
{
    #[Route('/research', name: 'app_research', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();
        $player = $planet->getOwner();

        $categories = [];
        foreach (ResearchType::cases() as $type) {
            $nextLevel = $player->getResearchLevel($type) + 1;
            $cost = $this->economy->researchCost($type, $nextLevel);
            $categories[$type->category()][] = [
                'type' => $type,
                'level' => $nextLevel - 1,
                'maxed' => null !== $type->maxLevel() && $nextLevel > $type->maxLevel(),
                'cost' => $cost,
                'seconds' => $this->economy->researchSeconds($type, $nextLevel, $planet, \DateTimeImmutable::createFromInterface($this->clock->now())),
                'missing' => $this->economy->missingRequirements($type->requirements(), $planet),
                'affordable' => $planet->getResources()->covers($cost),
            ];
        }

        return $this->renderGame('game/research.html.twig', $planet, [
            'categories' => $categories,
            'player' => $player,
            'colony_slots' => $this->economy->colonySlots($player),
            'owned_slots' => Economy::ownedSlots($player),
            'fusion_capacity' => $this->economy->fusionCapacity($player),
        ]);
    }

    #[Route('/research/{type}/start', name: 'app_research_start', requirements: ['type' => new EnumRequirement(ResearchType::class)], methods: ['POST'])]
    #[IsCsrfTokenValid('research', tokenKey: '_token')]
    public function start(ResearchType $type): Response
    {
        $planet = $this->currentPlanet();
        $this->attempt(fn () => $this->planetService->research($planet, $type), t('Forschung an {type} gestartet.', ['{type}' => t($type->label())]));

        return $this->redirectToRoute('app_research');
    }

    #[Route('/research/cancel', name: 'app_research_cancel', methods: ['POST'])]
    #[IsCsrfTokenValid('research', tokenKey: '_token')]
    public function cancel(): Response
    {
        $planet = $this->currentPlanet();
        $this->attempt(fn () => $this->planetService->cancelResearch($planet), t('Forschung abgebrochen, Rohstoffe wurden erstattet.'));

        return $this->redirectToRoute('app_research');
    }
}
