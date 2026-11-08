<?php

namespace App\Controller;

use App\Entity\FusionatorOffer;
use App\Game\FusionatorMarket;
use App\Game\GameException;
use App\Game\LifeForm;
use App\Game\ShipType;
use App\Repository\AllianceRepository;
use App\Repository\FusionatorOfferRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** Trade › Fusionatoren: traders offer fusionators, everybody allowed may buy them. */
class FusionatorMarketController extends AbstractGameController
{
    public function __construct(
        private readonly FusionatorMarket $market,
        private readonly FusionatorOfferRepository $offers,
    ) {
    }

    #[Route('/trade/fusionators', name: 'app_trade_fusionators', methods: ['GET'])]
    public function index(AllianceRepository $alliances): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();

        $mine = [];
        $market = [];
        foreach ($this->offers->findAllByPrice() as $offer) {
            if ($offer->getSeller()->isSameAs($player)) {
                $mine[] = $offer;
            } elseif ($offer->isAvailableTo($player)) {
                $market[] = ['offer' => $offer, 'seconds' => $this->market->deliverySeconds($offer, $player)];
            }
        }

        return $this->renderGame('game/trade_fusionators.html.twig', $planet, [
            'market' => $market,
            'mine' => $mine,
            'home' => $this->market->mainPlanet($player),
            'is_trader' => LifeForm::Trader === $player->getLifeForm(),
            'in_hangar' => $planet->getShipCount(ShipType::Fusionator),
            'life_forms' => LifeForm::cases(),
            'alliances' => $alliances->findAllByName(),
        ]);
    }

    #[Route('/trade/fusionators/offer', name: 'app_fusionator_offer', methods: ['POST'])]
    #[IsCsrfTokenValid('fusionator_market', tokenKey: '_token')]
    public function offer(Request $request): Response
    {
        $planet = $this->currentPlanet();
        $form = $request->request;
        $quantity = $form->getInt('quantity');
        $this->attempt(
            fn () => $this->market->offer(
                $planet,
                $quantity,
                (float) str_replace(',', '.', $form->getString('price')),
                array_values(array_filter(array_map(static fn (mixed $v): ?LifeForm => LifeForm::tryFrom((string) $v), $form->all('life_forms')))),
                array_map('intval', $form->all('alliances')),
            ),
            \sprintf('%d Fusionator(en) eingestellt.', $quantity),
        );

        return $this->redirectToRoute('app_trade_fusionators');
    }

    #[Route('/trade/fusionators/{id}/withdraw', name: 'app_fusionator_withdraw', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fusionator_market', tokenKey: '_token')]
    public function withdraw(FusionatorOffer $offer): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->market->withdraw($offer, $this->player()), 'Angebot zurückgezogen, die Fusionatoren stehen wieder im Hangar.');

        return $this->redirectToRoute('app_trade_fusionators');
    }

    #[Route('/trade/fusionators/{id}/buy', name: 'app_fusionator_buy', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('fusionator_market', tokenKey: '_token')]
    public function buy(FusionatorOffer $offer, Request $request): Response
    {
        $this->currentPlanet();
        // "Alle kaufen" takes the whole pack.
        $quantity = $request->request->has('all') ? $offer->getQuantity() : $request->request->getInt('quantity');
        try {
            $this->addFlash('success', $this->market->buy($offer, $this->player(), $quantity));
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_trade_fusionators');
    }
}
