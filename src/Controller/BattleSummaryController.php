<?php

namespace App\Controller;

use App\Game\BattleGroup;
use App\Game\BattleReports;
use App\Game\BattleSummary;
use App\Game\BattleSummaryForm;
use App\Game\ShipType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/** Battle summary: paste the links of many shared battle reports (a war, a long raid) and see the totals. */
class BattleSummaryController extends AbstractGameController
{
    #[Route('/kampfbilanz', name: 'app_battle_summary', methods: ['GET'])]
    public function index(): Response
    {
        return $this->renderGame('game/battle_summary.html.twig', $this->currentPlanet(), ['links' => '', 'summary' => null, 'missing' => [], 'ship_labels' => self::shipLabels()]);
    }

    #[Route('/kampfbilanz', name: 'app_battle_summary_run', methods: ['POST'])]
    public function run(#[MapRequestPayload] BattleSummaryForm $form, BattleReports $reports): Response
    {
        $planet = $this->currentPlanet();
        $hashes = $form->hashes();
        if (\count($hashes) > BattleSummary::MAX_REPORTS) {
            $this->addFlash('error', t('Höchstens {value} Kampfberichte auf einmal – die ersten {value} werden zusammengefasst.', ['{value}' => BattleSummary::MAX_REPORTS]));
            $hashes = \array_slice($hashes, 0, BattleSummary::MAX_REPORTS);
        }
        if ([] === $hashes) {
            $this->addFlash('error', t('Im Text habe ich keinen Link zu einem Kampfbericht gefunden.'));
        }

        $found = [];
        $missing = [];
        foreach ($hashes as $hash) {
            $report = $reports->find($hash);
            if (null === $report) {
                $missing[] = $hash;
            } else {
                $found[] = $report;
            }
        }

        return $this->renderGame('game/battle_summary.html.twig', $planet, [
            'links' => $form->links,
            'summary' => [] === $found ? null : new BattleSummary($found),
            'attacker' => BattleGroup::ATTACKER,
            'defender' => BattleGroup::DEFENDER,
            'missing' => $missing,
            'ship_labels' => self::shipLabels(),
        ]);
    }

    /** @return array<string, string> ship type => German label (translated in the template) */
    private static function shipLabels(): array
    {
        $labels = [];
        foreach (ShipType::cases() as $type) {
            $labels[$type->value] = $type->label();
        }

        return $labels;
    }
}
