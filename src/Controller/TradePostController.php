<?php

namespace App\Controller;

use App\I18n\NumberInput;
use App\Entity\Fleet;
use App\Entity\Planet;
use App\Entity\TradePost;
use App\Game\FleetMission;
use App\Game\GameException;
use App\Game\LifeForm;
use App\Game\ResourceType;
use App\Game\Resources;
use App\Game\TradeDeal;
use App\Game\TradeService;
use App\Repository\AllianceRepository;
use App\Repository\FleetRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** Page of one trade post: prices, stock, trading with waiting fleets; the owner manages it here. */
class TradePostController extends AbstractGameController
{
    public function __construct(private readonly TradeService $trade)
    {
    }

    #[Route('/trade-post/{id}', name: 'app_trade_post', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(TradePost $post, FleetRepository $fleets, AllianceRepository $alliances): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $own = $post->getOwner()->isSameAs($player);

        // The player's fleets waiting here can trade.
        $waiting = array_values(array_filter(
            $fleets->findIdleAt($post->getGalaxy(), $post->getSystem(), $post->getPosition()),
            static fn (Fleet $fleet): bool => $fleet->getOwner()->isSameAs($player),
        ));
        $visitors = [];
        if ($own) {
            foreach ($fleets->findForeignIdleAt($post->getGalaxy(), $post->getSystem(), $post->getPosition(), $player) as $fleet) {
                $visitors[] = ['fleet' => $fleet, 'plan' => $this->trade->sendAwayPlan($fleet)];
            }
        }

        return $this->renderGame('game/trade_post.html.twig', $planet, [
            'post' => $post,
            'own' => $own,
            'access' => $post->isAccessibleTo($player),
            'goods' => TradeDeal::goods(),
            'resources' => ResourceType::cases(),
            'waiting' => $waiting,
            'visitors' => $visitors,
            'can_send_away' => $own && $post->isActive() && LifeForm::Trader === $player->getLifeForm(),
            'life_forms' => LifeForm::cases(),
            'alliances' => $alliances->findAllByName(),
        ]);
    }

    #[Route('/trade-post/{id}/trade', name: 'app_trade_post_trade', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('trade', tokenKey: '_token')]
    public function trade(TradePost $post, Request $request, FleetRepository $fleets): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        $fleet = $fleets->find($form->getInt('fleet'));
        $amounts = static fn (array $values): array => array_map(static fn (mixed $v): float => NumberInput::amount((string) $v), $values);
        try {
            if (null === $fleet) {
                throw new GameException(t('Wähle eine Flotte aus, die hier wartet.'));
            }
            $this->addFlash('success', $this->trade->trade($post, $fleet, $this->player(), $amounts($form->all('buy')), $amounts($form->all('sell'))));
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());

            return $this->redirectToRoute('app_trade_post', ['id' => $post->getId()]);
        }

        // "Handeln und zurückfliegen": back to the planet the fleet came from (if still own, else the
        // main planet); fuel and provisions come from the cargo.
        if ($form->has('return')) {
            $planets = $this->player()->getPlanets();
            $origin = $fleet->getHome();
            $home = (null === $origin ? null : $planets->findFirst(static fn (int $key, Planet $p): bool => $p->getGalaxy() === $origin[0] && $p->getSystem() === $origin[1] && $p->covers($origin[2])))
                ?? $planets->findFirst(static fn (int $key, Planet $p): bool => $p->isHomeworld()) ?? $planets->first();
            try {
                if (!$home instanceof Planet) {
                    throw new GameException(t('Du hast keinen Planeten, zu dem die Flotte zurückfliegen kann.'));
                }
                // With cargo the way home is a transport (visible); it stays at the planet it came from.
                $fleet->rememberHome($home->getGalaxy(), $home->getSystem(), $home->getPosition());
                $mission = $fleet->getCargo()->isEmpty() ? FleetMission::Station : FleetMission::TransportStation;
                $this->addFlash('success', $this->fleetService->sendFleet($fleet, $this->player(), $home->getGalaxy(), $home->getSystem(), $home->getPosition(), $mission, new Resources()));
            } catch (GameException $e) {
                $this->addFlash('error', t('Rückflug nicht möglich: {error}', ['{error}' => $e->translatable()]));
            }
        }

        return $this->redirectToRoute('app_trade_post', ['id' => $post->getId()]);
    }
}
