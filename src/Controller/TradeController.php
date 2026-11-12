<?php

namespace App\Controller;

use App\I18n\NumberInput;
use App\Entity\TradePost;
use App\Game\GameException;
use App\Game\LifeForm;
use App\Game\TradeDeal;
use App\Game\TradeService;
use App\Repository\AllianceRepository;
use App\Repository\FleetRepository;
use App\Repository\TradePostRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

class TradeController extends AbstractGameController
{
    public function __construct(
        private readonly TradeService $trade,
        private readonly TradePostRepository $posts,
    ) {
    }

    #[Route('/trade', name: 'app_trade', methods: ['GET'])]
    public function index(AllianceRepository $alliances, FleetRepository $fleets, \App\Game\TradeGuild $guild): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();

        // The trade guild has a post in every galaxy: listed apart, only the one of this galaxy.
        $all = array_values(array_filter($this->posts->findAllOrdered(), static fn (TradePost $p): bool => !$p->getOwner()->isSystem()));
        $mine = array_values(array_filter($all, static fn (TradePost $p): bool => $p->getOwner()->isSameAs($player)));

        // Foreign fleets waiting at the trader's posts, with the cost of sending them home.
        $visitors = [];
        foreach ($mine as $post) {
            foreach ($fleets->findForeignIdleAt($post->getGalaxy(), $post->getSystem(), $post->getPosition(), $player) as $fleet) {
                $visitors[$post->getId()][] = ['fleet' => $fleet, 'plan' => $this->trade->sendAwayPlan($fleet)];
            }
        }

        return $this->renderGame('game/trade.html.twig', $planet, [
            'posts' => $all,
            'mine' => $mine,
            'visitors' => $visitors,
            'goods' => TradeDeal::goods(),
            'averages' => $this->posts->averagePrices(),
            'allowed_posts' => $this->trade->allowedPosts($player),
            'is_trader' => LifeForm::Trader === $player->getLifeForm(),
            'life_forms' => LifeForm::cases(),
            'alliances' => $alliances->findAllByName(),
            'guild_post' => $guild->postIn($planet->getGalaxy()),
            'guild_sell' => \App\Game\TradeGuild::sellPrices(),
            'guild_buy' => \App\Game\TradeGuild::buyPrices(),
            'guild_fusionator_price' => \App\Game\TradeGuild::fusionatorPrice(),
            'guild_fusionator_galaxy' => \App\Game\TradeGuild::FUSIONATOR_GALAXY,
        ]);
    }

    #[Route('/trade/open', name: 'app_trade_open', methods: ['POST'])]
    #[IsCsrfTokenValid('trade', tokenKey: '_token')]
    public function open(Request $request): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $this->attempt(
            function () use ($form): void {
                $planet = $this->player()->getPlanets()->findFirst(static fn (int $k, \App\Entity\Planet $p): bool => $p->getId() === $form->getInt('planet'))
                    ?? throw new \App\Game\GameException(t('Wähle eine deiner Kolonien.'));
                $this->trade->open($this->player(), $form->getString('name'), $planet);
            },
            t('Handelsstützpunkt eingerichtet. Lege Preise und Ankauf fest – gehandelt wird mit den Rohstoffen des Planeten.'),
        );

        return $this->redirectToRoute('app_trade');
    }

    #[Route('/trade/{id}/configure', name: 'app_trade_configure', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('trade', tokenKey: '_token')]
    public function configure(TradePost $post, Request $request): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $lifeForms = array_values(array_filter(array_map(static fn (mixed $v): ?LifeForm => LifeForm::tryFrom((string) $v), $form->all('life_forms'))));
        $this->attempt(
            fn () => $this->trade->configure(
                $post,
                $this->player(),
                $form->getString('name'),
                array_map(static fn (mixed $v): float => NumberInput::decimal((string) $v, $request->getLocale()), $form->all('prices')),
                $lifeForms,
                array_map('intval', $form->all('alliances')),
                array_map(static fn (mixed $v): float => NumberInput::decimal((string) $v, $request->getLocale()), $form->all('buy_prices')),
                array_map(static fn (mixed $v): float => NumberInput::amount((string) $v), $form->all('buy_limits')),
            ),
            t('Handelsstützpunkt gespeichert.'),
        );

        return $this->redirectToRoute('app_trade_post', ['id' => $post->getId()]);
    }

    /** Sends every foreign fleet at the post home, e.g. when the post is about to be attacked. */
    #[Route('/trade/{id}/send-away-all', name: 'app_trade_send_away_all', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('trade', tokenKey: '_token')]
    public function sendAwayAll(TradePost $post, FleetRepository $fleets): Response
    {
        $this->currentPlanet();
        $player = $this->player();
        $visitors = $fleets->findForeignIdleAt($post->getGalaxy(), $post->getSystem(), $post->getPosition(), $player);
        if ([] === $visitors) {
            $this->addFlash('error', t('An diesem Stützpunkt wartet keine fremde Flotte.'));
        }
        foreach ($visitors as $visitor) {
            try {
                $this->trade->sendAway($post, $player, $visitor);
                $this->addFlash('success', t('{label} von {player} fliegt zurück zu ihrem Heimatplaneten.', ['{label}' => $visitor->getLabel(), '{player}' => $visitor->getOwner()->getUsername()]));
            } catch (GameException $e) {
                $this->addFlash('error', t('{label} von {player}: {message}', ['{label}' => $visitor->getLabel(), '{player}' => $visitor->getOwner()->getUsername(), '{message}' => $e->getMessage()]));
            }
        }

        return $this->redirectToRoute('app_trade_post', ['id' => $post->getId()]);
    }

    #[Route('/trade/{id}/send-away/{fleet}', name: 'app_trade_send_away', requirements: ['id' => Requirement::DIGITS, 'fleet' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('trade', tokenKey: '_token')]
    public function sendAway(TradePost $post, int $fleet, FleetRepository $fleets): Response
    {
        $this->currentPlanet();
        $visitor = $fleets->find($fleet);
        if (null === $visitor) {
            $this->addFlash('error', t('Diese Flotte gibt es nicht mehr.'));
        } else {
            $this->attempt(fn () => $this->trade->sendAway($post, $this->player(), $visitor), t('Die Flotte von {player} fliegt zurück zu ihrem Heimatplaneten.', ['{player}' => $visitor->getOwner()->getUsername()]));
        }

        return $this->redirectToRoute('app_trade_post', ['id' => $post->getId()]);
    }

    #[Route('/trade/{id}/close', name: 'app_trade_close', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('trade', tokenKey: '_token')]
    public function close(TradePost $post): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->trade->close($post, $this->player()), t('Handelsstützpunkt geschlossen.'));

        return $this->redirectToRoute('app_trade');
    }
}
