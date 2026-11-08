<?php

namespace App\Controller;

use App\Game\BuildingType;
use App\Game\Fusion;
use App\Game\FusionService;
use App\Game\Universe;
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
        // Preview: everything that could be welded from here, and how many fusionators that takes.
        $chain = $this->fusion->chain($planet, Universe::MAX_PLANETS_PER_SYSTEM);

        // Building levels after the fusion: sum over the top planet and the chain.
        $preview = [];
        foreach (BuildingType::cases() as $type) {
            $levels = array_map(static fn ($p): int => $p->getBuildingLevel($type), [$planet, ...$chain]);
            if (array_sum($levels) > 0) {
                $preview[] = ['type' => $type, 'levels' => $levels, 'total' => array_sum($levels)];
            }
        }

        return $this->renderGame('game/fusion.html.twig', $planet, [
            'chain' => $chain,
            'preview' => $preview,
            'capacity' => $this->economy->fusionCapacity($planet->getOwner()),
            'needed' => $this->fusion->fusionatorsNeeded($planet, $chain),
            'pending' => [] === $chain ? [] : $this->fusion->pendingWork([$planet, ...$chain]),
            'stop_reason' => $this->fusion->stopReason($planet, Universe::MAX_PLANETS_PER_SYSTEM),
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
