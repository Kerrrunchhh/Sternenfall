<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Find players by name. */
class PlayerSearchController extends AbstractGameController
{
    #[Route('/spieler', name: 'app_player_search', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        $planet = $this->currentPlanet();
        $query = trim($request->query->getString('q'));
        $found = [];
        if (mb_strlen($query) >= 2) {
            $found = $users->createQueryBuilder('u')
                ->where('u.username LIKE :q AND u.system = false AND u.deactivatedAt IS NULL')
                ->setParameter('q', '%'.addcslashes($query, '%_').'%')
                ->orderBy('u.username', 'ASC')
                ->setMaxResults(50)
                ->getQuery()->getResult();
        }

        return $this->renderGame('game/player_search.html.twig', $planet, ['query' => $query, 'found' => $found]);
    }
}
