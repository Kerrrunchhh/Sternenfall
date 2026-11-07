<?php

namespace App\Controller;

use App\Entity\Planet;
use App\Game\QueueKind;
use App\Game\QueueTimeline;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\EnumRequirement;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

class QueueController extends AbstractGameController
{
    private const array RETURN_ROUTES = ['app_queues', 'app_buildings', 'app_shipyard', 'app_overview'];

    #[Route('/queues', name: 'app_queues', methods: ['GET'])]
    public function index(QueueTimeline $timeline): Response
    {
        $planet = $this->currentPlanet();

        $planets = [];
        foreach ($planet->getOwner()->getPlanets() as $p) {
            $planets[] = [
                'planet' => $p,
                'buildings' => $timeline->entries($p, QueueKind::Buildings),
                'shipyard' => $timeline->entries($p, QueueKind::Shipyard),
            ];
        }

        return $this->renderGame('game/queues.html.twig', $planet, ['planets' => $planets]);
    }

    #[Route('/planet/{id}/queue/{kind}/reorder', name: 'app_queue_reorder', requirements: ['id' => Requirement::DIGITS, 'kind' => new EnumRequirement(QueueKind::class)], methods: ['POST'])]
    #[IsCsrfTokenValid('queue', tokenKey: '_token')]
    public function reorder(Planet $planet, QueueKind $kind, Request $request): Response
    {
        $this->assertOwned($planet);
        $ids = array_map('intval', $request->request->all('order'));

        // Accessible alternative to drag & drop: "id:up" / "id:down" buttons.
        if ('' !== $move = $request->request->getString('move')) {
            [$id, $direction] = explode(':', $move) + [1 => ''];
            $index = array_search((int) $id, $ids, true);
            $target = false === $index ? false : $index + ('up' === $direction ? -1 : 1);
            if (false !== $target && isset($ids[$target])) {
                [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            }
        }

        $this->attempt(fn () => $this->planetService->reorder($planet, $kind, $ids), 'Reihenfolge gespeichert.');

        return $this->back($request);
    }

    #[Route('/planet/{id}/queue/{kind}/{orderId}/cancel', name: 'app_queue_cancel', requirements: ['id' => Requirement::DIGITS, 'orderId' => Requirement::DIGITS, 'kind' => new EnumRequirement(QueueKind::class)], methods: ['POST'])]
    #[IsCsrfTokenValid('queue', tokenKey: '_token')]
    public function cancel(Planet $planet, QueueKind $kind, int $orderId, Request $request): Response
    {
        $this->assertOwned($planet);
        $this->attempt(fn () => $this->planetService->cancelOrder($planet, $kind, $orderId), 'Auftrag abgebrochen, Kosten wurden erstattet.');

        return $this->back($request);
    }

    private function assertOwned(Planet $planet): void
    {
        $this->fleetService->processDue();
        if ($planet->getOwner()->getId() !== $this->player()->getId()) {
            throw $this->createAccessDeniedException();
        }
    }

    private function back(Request $request): Response
    {
        $route = $request->request->getString('to');

        return $this->redirectToRoute(\in_array($route, self::RETURN_ROUTES, true) ? $route : 'app_queues');
    }
}
