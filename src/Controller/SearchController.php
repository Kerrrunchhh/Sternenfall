<?php

namespace App\Controller;

use App\Repository\AllianceRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The search field at the top of every page: players and alliances by name (alliances also by
 * tag). Exact matches first, then names starting with the term, then the rest – each alphabetically.
 */
class SearchController extends AbstractGameController
{
    public const int MAX_RESULTS = 50;

    #[Route('/suche', name: 'app_search', methods: ['GET'])]
    public function index(Request $request, UserRepository $users, AllianceRepository $alliances): Response
    {
        $planet = $this->currentPlanet();
        $query = trim($request->query->getString('q'));
        $players = [];
        $found = [];
        if (mb_strlen($query) >= 2) {
            $like = addcslashes($query, '%_\\');
            $players = $users->createQueryBuilder('u')
                ->addSelect('CASE WHEN LOWER(u.username) = LOWER(:exact) THEN 0 WHEN u.username LIKE :prefix THEN 1 ELSE 2 END AS HIDDEN relevance')
                ->where('u.username LIKE :contains AND u.system = false AND u.deactivatedAt IS NULL')
                ->setParameter('exact', $query)
                ->setParameter('prefix', $like.'%')
                ->setParameter('contains', '%'.$like.'%')
                ->orderBy('relevance', 'ASC')
                ->addOrderBy('u.username', 'ASC')
                ->setMaxResults(self::MAX_RESULTS)
                ->getQuery()->getResult();
            $found = $alliances->createQueryBuilder('a')
                ->addSelect('CASE WHEN LOWER(a.tag) = LOWER(:exact) OR LOWER(a.name) = LOWER(:exact) THEN 0 WHEN a.name LIKE :prefix OR a.tag LIKE :prefix THEN 1 ELSE 2 END AS HIDDEN relevance')
                ->where('a.name LIKE :contains OR a.tag LIKE :contains')
                ->setParameter('exact', $query)
                ->setParameter('prefix', $like.'%')
                ->setParameter('contains', '%'.$like.'%')
                ->orderBy('relevance', 'ASC')
                ->addOrderBy('a.name', 'ASC')
                ->setMaxResults(self::MAX_RESULTS)
                ->getQuery()->getResult();
        }
        // The tab asked for, else the one with results (players first).
        $tab = $request->query->getString('tab');
        if (!\in_array($tab, ['players', 'alliances'], true)) {
            $tab = [] === $players && [] !== $found ? 'alliances' : 'players';
        }

        return $this->renderGame('game/search.html.twig', $planet, [
            'query' => $query,
            'tab' => $tab,
            'players' => $players,
            'alliances' => $found,
            'max_results' => self::MAX_RESULTS,
        ]);
    }

    /** The former player search: its links lead to the search. */
    #[Route('/spieler', name: 'app_player_search', methods: ['GET'])]
    public function players(Request $request): Response
    {
        return $this->redirectToRoute('app_search', array_filter(['q' => trim($request->query->getString('q'))]), Response::HTTP_MOVED_PERMANENTLY);
    }
}
