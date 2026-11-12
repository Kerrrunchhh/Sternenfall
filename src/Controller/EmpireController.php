<?php

namespace App\Controller;

use App\Entity\Planet;
use App\Game\BuildingType;
use App\Game\QueueKind;
use App\Game\QueueTimeline;
use App\Game\ResourceType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** All own planets at a glance: resources, building levels and what is being built – summed up at the bottom. */
class EmpireController extends AbstractGameController
{
    #[Route('/imperium', name: 'app_empire', methods: ['GET'])]
    public function index(QueueTimeline $timeline): Response
    {
        $current = $this->currentPlanet();
        $player = $current->getOwner();

        $rows = [];
        $sums = ['resources' => array_fill_keys(array_map(static fn (ResourceType $t): string => $t->value, ResourceType::cases()), 0.0), 'population' => 0.0, 'buildings' => array_fill_keys(array_map(static fn (BuildingType $t): string => $t->value, BuildingType::cases()), 0), 'queues' => array_fill_keys(array_map(static fn (QueueKind $k): string => $k->value, QueueKind::cases()), 0)];
        foreach ($player->getPlanets() as $planet) {
            // Every planet brought up to date, so the figures are those of right now.
            $this->planetService->synchronize($planet);
            $queues = [];
            foreach (QueueKind::cases() as $kind) {
                $queues[$kind->value] = $timeline->entries($planet, $kind);
                $sums['queues'][$kind->value] += \count($queues[$kind->value]);
            }
            foreach (ResourceType::cases() as $type) {
                $sums['resources'][$type->value] += $planet->getResources()->get($type);
            }
            $sums['population'] += $planet->getPopulation();
            foreach (BuildingType::cases() as $type) {
                $sums['buildings'][$type->value] += $planet->getBuildingLevel($type);
            }
            $rows[] = ['planet' => $planet, 'queues' => $queues];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['planet']->isHomeworld(), $a['planet']->getGalaxy(), $a['planet']->getSystem(), $a['planet']->getPosition()] <=> [$a['planet']->isHomeworld(), $b['planet']->getGalaxy(), $b['planet']->getSystem(), $b['planet']->getPosition()]);

        return $this->renderGame('game/empire.html.twig', $current, [
            'rows' => $rows,
            'sums' => $sums,
            'resource_types' => ResourceType::cases(),
            'building_types' => BuildingType::cases(),
            'queue_kinds' => QueueKind::cases(),
        ]);
    }
}
