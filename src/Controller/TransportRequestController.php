<?php

namespace App\Controller;

use App\I18n\NumberInput;
use App\Entity\Planet;
use App\Entity\TransportRequest;
use App\Game\GameException;
use App\Game\LifeForm;
use App\Game\Resources;
use App\Game\ResourceType;
use App\Game\TransportRequestDispatcher;
use App\Game\TransportRequests;
use App\Repository\TransportRequestRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** Transport requests: beginners ask for resources, traders deliver many at once. */
class TransportRequestController extends AbstractGameController
{
    public function __construct(
        private readonly TransportRequests $service,
        private readonly TransportRequestDispatcher $dispatcher,
        private readonly TransportRequestRepository $requests,
    ) {
    }

    #[Route('/transportgesuche', name: 'app_transport_requests', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $trader = LifeForm::Trader === $player->getLifeForm();

        return $this->renderGame('game/transport_requests.html.twig', $planet, [
            'open' => array_values(array_filter($this->requests->findOpen(), static fn (TransportRequest $r): bool => !$r->getRequester()->isSameAs($player))),
            'mine' => $this->requests->findOf($player),
            'max_load' => TransportRequests::maxLoad(),
            'resource_types' => ResourceType::cases(),
            'is_trader' => $trader,
            'ready_fleets' => $trader ? $this->dispatcher->readyFleets($player, $planet) : [],
        ]);
    }

    #[Route('/transportgesuche/neu', name: 'app_transport_request_create', methods: ['POST'])]
    #[IsCsrfTokenValid('transport_requests', tokenKey: '_token')]
    public function create(Request $request): Response
    {
        $this->currentPlanet();
        $f = $request->request;
        $target = $this->player()->getPlanets()->findFirst(static fn (int $k, Planet $p): bool => $p->getId() === $f->getInt('planet'));
        $goods = Resources::fromArray(array_map(static fn ($v): float => max(0, NumberInput::amount((string) $v)), $f->all('goods')));
        $this->attempt(function () use ($target, $goods, $f): void {
            if (null === $target) {
                throw new GameException(t('Wähle einen deiner Planeten.'));
            }
            $this->service->create($this->player(), $target, $goods, NumberInput::amount($f->getString('offer')));
        }, t('Transportgesuch eingestellt – das Angebot ist verwahrt, bis die Lieferung ankommt.'));

        return $this->redirectToRoute('app_transport_requests');
    }

    #[Route('/transportgesuche/{id}/zurueckziehen', name: 'app_transport_request_cancel', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('transport_requests', tokenKey: '_token')]
    public function cancel(TransportRequest $transportRequest): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->cancel($transportRequest, $this->player()), t('Gesuch zurückgezogen, die Credits sind wieder bei dir.'));

        return $this->redirectToRoute('app_transport_requests');
    }

    #[Route('/transportgesuche/beliefern', name: 'app_transport_request_serve', methods: ['POST'])]
    #[IsCsrfTokenValid('transport_requests', tokenKey: '_token')]
    public function serve(Request $request): Response
    {
        $planet = $this->currentPlanet();
        $ids = array_map('intval', $request->request->all('requests'));
        $chosen = [] === $ids ? [] : $this->requests->findBy(['id' => $ids], ['createdAt' => 'ASC']);
        try {
            if ([] === $chosen) {
                throw new GameException(t('Wähle mindestens ein Gesuch.'));
            }
            foreach ($this->dispatcher->serve($this->player(), $planet, $chosen) as $line) {
                $this->addFlash('success', $line);
            }
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        return $this->redirectToRoute('app_transport_requests');
    }
}
