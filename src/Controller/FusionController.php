<?php

namespace App\Controller;

use App\Game\BuildingType;
use App\Game\Fusion;
use App\Game\FusionService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class FusionController extends AbstractGameController
{
    public function __construct(private readonly FusionService $fusion)
    {
    }

    #[Route('/fusion', name: 'app_fusion', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();
        // Preview: this planet welded onto the own planet directly above.
        ['top' => $top, 'reason' => $reason] = $this->fusion->plan($planet);

        // Building levels after the fusion: sum of both.
        $preview = [];
        foreach (null === $top ? [] : BuildingType::cases() as $type) {
            $levels = array_map(static fn ($p): int => $p->getBuildingLevel($type), [$top, $planet]);
            if (array_sum($levels) > 0) {
                $preview[] = ['type' => $type, 'levels' => $levels, 'total' => array_sum($levels)];
            }
        }

        return $this->renderGame('game/fusion.html.twig', $planet, [
            'top' => $top,
            'preview' => $preview,
            'capacity' => $this->economy->fusionCapacity($planet->getOwner()),
            'pending' => null === $top ? [] : $this->fusion->pendingWork([$top, $planet]),
            'stop_reason' => $reason,
            'integrating' => $planet->isIntegrating($this->clock->now()),
            'split_cost' => Fusion::splitCost($planet),
            'shield' => $this->economy->planetShield($planet),
        ]);
    }

    #[Route('/fusion/split', name: 'app_fusion_split', methods: ['POST'])]
    #[IsCsrfTokenValid('fusion', tokenKey: '_token')]
    public function split(): Response
    {
        $planet = $this->currentPlanet();
        $this->attempt(fn () => $this->fusion->split($planet), 'Die Fusion wurde gesprengt.');

        return $this->redirectToRoute('app_fusion');
    }
}
