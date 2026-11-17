<?php

namespace App\Controller;

use App\Game\Economy;
use App\Game\LifeForm;
use App\Game\ResearchType;
use App\Game\ShipType;
use App\Game\Universe;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Distance calculator: two coordinates, ships and drive research to try out – the browser
 * computes distance, flight times and consumption with the same formulas as the game.
 */
class DistanceCalculatorController extends AbstractGameController
{
    #[Route('/entfernungsrechner', name: 'app_distance_calculator', methods: ['GET'])]
    public function index(TranslatorInterface $translator): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();

        $ships = [];
        foreach (ShipType::cases() as $type) {
            if ($type->isStationary()) {
                continue;
            }
            $spec = $type->spec();
            $ships[] = ['type' => $type->value, 'label' => $translator->trans($type->label()), 'speed' => $spec->speed, 'drive' => $spec->drive->value, 'fuel' => $spec->fuel, 'crew' => $spec->crew];
        }
        // The research that makes ships faster: the four drives and espionage (the probe's drive).
        $drives = [];
        foreach (ResearchType::cases() as $research) {
            if ('Antriebe' === $research->category() || ResearchType::Espionage === $research) {
                $drives[] = ['type' => $research->value, 'label' => $translator->trans($research->label()), 'level' => $player->getResearchLevel($research)];
            }
        }
        $lifeForms = array_map(static fn (LifeForm $l): array => ['value' => $l->value, 'label' => $translator->trans($l->label()), 'factor' => $l->speedFactor()], LifeForm::cases());

        return $this->renderGame('game/distance_calculator.html.twig', $planet, [
            'calc' => [
                'ships' => $ships,
                'gameSpeed' => $this->economy->speed(),
                'foodPerCrew' => Economy::FOOD_PER_INHABITANT,
                'waterPerCrew' => Economy::WATER_PER_INHABITANT,
                'researchBonus' => \App\Game\Battle::RESEARCH_BONUS,
            ],
            'drives' => $drives,
            'life_forms' => $lifeForms,
            'own_life_form' => $player->getLifeForm()->value,
            'from' => [$planet->getGalaxy(), $planet->getSystem(), $planet->getPosition()],
            'to' => [$planet->getGalaxy(), min(Universe::SYSTEMS_PER_GALAXY, $planet->getSystem() + 1), 1],
            'galaxies' => Universe::GALAXIES,
            'systems' => Universe::SYSTEMS_PER_GALAXY,
        ]);
    }
}
