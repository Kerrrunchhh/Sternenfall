<?php

namespace App\Controller;

use App\Game\BattleGroup;
use App\Game\ShipType;
use App\Game\Simulator\BattleSimulator;
use App\Game\Simulator\CombatResearch;
use App\Game\Simulator\ForceParser;
use App\Game\Simulator\SimulatorForm;
use App\Game\Simulator\SimulatorSide;
use App\I18n\NumberInput;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * Battle simulator: paste a spy report (or a ship list) for each side, adjust ships and combat
 * research, and see how the attack would go. Nothing happens in the game.
 */
class SimulatorController extends AbstractGameController
{
    #[Route('/kampfsimulator', name: 'app_simulator', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();
        $own = [];
        foreach (CombatResearch::types() as $type) {
            $own[$type->value] = $this->player()->getResearchLevel($type);
        }

        // The attacker starts with the player's own research.
        return $this->show($planet, ['ships' => [], 'research' => $own, 'owner' => null], ['ships' => [], 'research' => [], 'owner' => null]);
    }

    #[Route('/kampfsimulator', name: 'app_simulator_run', methods: ['POST'])]
    public function run(#[MapRequestPayload] SimulatorForm $form, ForceParser $parser, BattleSimulator $simulator, TranslatorInterface $translator): Response
    {
        $planet = $this->currentPlanet();
        $attacker = self::values($form->attacker, $form->attackerName);
        $defender = self::values($form->defender, $form->defenderName);

        if (SimulatorForm::SWAP === $form->do) {
            // Turrets stay on the planet: they can't attack and don't fight in space.
            $flying = static fn (array $ships): array => array_filter($ships, static fn (string $type): bool => !ShipType::from($type)->isStationary(), ARRAY_FILTER_USE_KEY);
            if (\count($flying($defender['ships'])) < \count($defender['ships'])) {
                $this->addFlash('success', t('Seiten getauscht. Die Türme des Verteidigers bleiben auf dem Planeten zurück.'));
            }

            return $this->show($planet, ['ships' => $flying($defender['ships'])] + $defender, $attacker);
        }

        if (SimulatorForm::SIMULATE !== $form->do) {
            // Read the pasted text into that side: its ships replace the typed ones, its research too if the text has any.
            $reading = SimulatorForm::READ_ATTACKER === $form->do ? $form->attacker : $form->defender;
            $parsed = $parser->parse($reading->report);
            $side = ['ships' => $parsed->ships, 'research' => [] === $parsed->research ? null : $parsed->research, 'owner' => $parsed->owner];
            if ([] === $parsed->ships && [] === $parsed->research) {
                $this->addFlash('error', t('Im eingefügten Text habe ich keine Schiffe und keine Forschung gefunden.'));
            } elseif ([] === $parsed->research) {
                $this->addFlash('success', t('Schiffe eingelesen. Der Text enthält keine Kampfforschung – die Forschung bleibt, wie sie war.'));
            } else {
                $this->addFlash('success', t('Schiffe und Forschung eingelesen.'));
            }
            if (SimulatorForm::READ_ATTACKER === $form->do) {
                $attacker = ['ships' => $side['ships'], 'research' => $side['research'] ?? $attacker['research'], 'owner' => $side['owner'] ?? $attacker['owner']];
            } else {
                $defender = ['ships' => $side['ships'], 'research' => $side['research'] ?? $defender['research'], 'owner' => $side['owner'] ?? $defender['owner']];
            }

            return $this->show($planet, $attacker, $defender);
        }

        $result = $simulator->simulate(
            $attacker['ships'], $attacker['research'], $defender['ships'], $defender['research'],
            '' !== $form->attackerName ? $form->attackerName : $translator->trans('Angreifer'),
            '' !== $form->defenderName ? $form->defenderName : $translator->trans('Verteidiger'),
        );

        return $this->show($planet, $attacker, $defender, [
            'attacker_won' => $result->attackerWon(),
            'attacker_lost' => BattleSimulator::lostValue($result, BattleGroup::ATTACKER),
            'defender_lost' => BattleSimulator::lostValue($result, BattleGroup::DEFENDER),
            'replay' => BattleReportController::replayView($result->replay(), -1, $translator),
        ]);
    }

    /**
     * @param array{ships: array<string, int>, research: array<string, int>, owner: ?string} $attacker
     * @param array{ships: array<string, int>, research: array<string, int>, owner: ?string} $defender
     * @param array<string, mixed>|null                                                         $result
     */
    private function show(\App\Entity\Planet $planet, array $attacker, array $defender, ?array $result = null): Response
    {
        return $this->renderGame('game/simulator.html.twig', $planet, [
            'attacker' => $attacker,
            'defender' => $defender,
            'research_types' => CombatResearch::types(),
            // Turrets only defend.
            'attacker_types' => array_values(array_filter(ShipType::cases(), static fn (ShipType $t): bool => !$t->isStationary())),
            'defender_types' => ShipType::cases(),
            'result' => $result,
        ]);
    }

    /** @return array{ships: array<string, int>, research: array<string, int>, owner: ?string} */
    private static function values(SimulatorSide $side, string $name): array
    {
        $ships = [];
        foreach ($side->ships as $type => $count) {
            $n = (int) NumberInput::amount((string) $count);
            if ($n > 0 && null !== ShipType::tryFrom((string) $type)) {
                $ships[(string) $type] = min(BattleSimulator::MAX_UNITS, $n);
            }
        }
        $research = [];
        foreach (CombatResearch::types() as $type) {
            $research[$type->value] = max(0, min(BattleSimulator::MAX_LEVEL, (int) NumberInput::amount((string) ($side->research[$type->value] ?? '0'))));
        }

        return ['ships' => $ships, 'research' => $research, 'owner' => '' !== $name ? $name : null];
    }
}
