<?php

namespace App\Controller;

use App\Entity\Planet;
use App\Game\BuildingType;
use App\Game\Economy;
use App\Game\NewbieProtection;
use App\Game\Requirement;
use App\Game\ResearchType;
use App\Game\ShipType;
use App\Game\TransportRequests;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Game guide: quick start and the tech tree of the early game, checked against the player's own progress. */
class GuideController extends AbstractGameController
{
    /** What counts as "early game": every requirement at most this level. */
    private const int EARLY_LEVEL = 3;

    #[Route('/spielhilfe', name: 'app_guide', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();

        $buildings = array_map(fn (BuildingType $t): array => $this->node($t->label(), $t->requirements(), $planet, 'app_buildings', [], 'building', $planet->getBuildingLevel($t)), BuildingType::cases());
        $research = [];
        foreach (ResearchType::cases() as $type) {
            if ($this->isEarly($type->requirements())) {
                $research[] = $this->node($type->label(), $type->requirements(), $planet, 'app_research', [], 'research', $planet->getOwner()->getResearchLevel($type));
            }
        }
        $ships = [];
        foreach (ShipType::cases() as $type) {
            if ($this->isEarly($type->spec()->requirements)) {
                $ships[] = $this->node($type->label(), $type->spec()->requirements, $planet, 'app_ship_info', ['type' => $type->value], $type->isStationary() ? 'defense' : 'ship');
            }
        }
        $order = static fn (array $a, array $b): int => [\count($a['requirements']), $a['weight']] <=> [\count($b['requirements']), $b['weight']];
        usort($research, $order);
        usort($ships, $order);

        return $this->renderGame('game/guide.html.twig', $planet, [
            'tree' => [
                ['title' => 'Gebäude', 'nodes' => $buildings],
                ['title' => 'Forschung', 'nodes' => $research],
                ['title' => 'Schiffe und Verteidigung', 'nodes' => $ships],
            ],
            'starter_bonus_hours' => Economy::STARTER_BONUS_HOURS,
            'newbie_protected' => NewbieProtection::isProtected($planet->getOwner()),
            'resources_per_credit' => TransportRequests::RESOURCES_PER_CREDIT,
        ]);
    }

    /** @param list<Requirement> $requirements */
    private function isEarly(array $requirements): bool
    {
        foreach ($requirements as $requirement) {
            if ($requirement->level > self::EARLY_LEVEL) {
                return false;
            }
        }

        return true;
    }

    /**
     * One entry of the tree: its requirements, each marked fulfilled or not for this player.
     *
     * @param list<Requirement>    $requirements
     * @param array<string, mixed> $routeParams
     *
     * @return array{label: string, kind: string, route: string, params: array<string, mixed>, requirements: list<array{label: \Symfony\Contracts\Translation\TranslatableInterface, met: bool}>, ready: bool, weight: int, level: ?int}
     */
    private function node(string $label, array $requirements, Planet $planet, string $route, array $routeParams, string $kind, ?int $level = null): array
    {
        $list = [];
        foreach ($requirements as $requirement) {
            $current = $requirement->subject instanceof BuildingType ? $planet->getBuildingLevel($requirement->subject) : $planet->getOwner()->getResearchLevel($requirement->subject);
            $list[] = ['label' => $requirement->label(), 'met' => $current >= $requirement->level];
        }

        return [
            'label' => $label,
            'kind' => $kind,
            'route' => $route,
            'params' => $routeParams,
            'requirements' => $list,
            'ready' => [] === array_filter($list, static fn (array $r): bool => !$r['met']),
            'weight' => array_sum(array_map(static fn (Requirement $r): int => $r->level, $requirements)),
            'level' => $level,
        ];
    }
}
