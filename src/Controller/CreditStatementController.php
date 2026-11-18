<?php

namespace App\Controller;

use App\Game\CreditLedger;
use App\Game\LifeForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Under "Handel": every player's credit statement, and for traders the sales report of their posts. */
class CreditStatementController extends AbstractGameController
{
    #[Route('/trade/kontoauszug', name: 'app_trade_statement', methods: ['GET'])]
    public function statement(Request $request, CreditLedger $ledger): Response
    {
        $planet = $this->currentPlanet();
        $direction = \in_array($request->query->getString('direction'), ['in', 'out'], true) ? $request->query->getString('direction') : null;
        $kind = \array_key_exists($request->query->getString('kind'), CreditLedger::KINDS) ? $request->query->getString('kind') : null;
        $bookings = $ledger->statement($this->player(), $direction, $kind);

        return $this->renderGame('game/credit_statement.html.twig', $planet, [
            'bookings' => $bookings,
            'income' => array_sum(array_map(static fn ($b): float => max(0, $b->getAmount()), $bookings)),
            'spent' => array_sum(array_map(static fn ($b): float => max(0, -$b->getAmount()), $bookings)),
            'direction' => $direction,
            'kind' => $kind,
            'kinds' => CreditLedger::KINDS,
            'keep_days' => CreditLedger::KEEP_DAYS,
        ]);
    }

    #[Route('/trade/auswertung', name: 'app_trade_report', methods: ['GET'])]
    public function report(CreditLedger $ledger): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();

        return $this->renderGame('game/trade_report.html.twig', $planet, [
            'rows' => $ledger->postReport($player),
            'trader' => LifeForm::Trader === $player->getLifeForm(),
            'keep_days' => CreditLedger::KEEP_DAYS,
        ]);
    }
}
