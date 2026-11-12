<?php

namespace App\Controller;

use App\Game\Highscores;
use App\Game\LifeForm;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Highscores of players and alliances, by points, combat experience or credits, per life form. */
class HighscoreController extends AbstractGameController
{
    private const int TOP = 100;

    #[Route('/highscores', name: 'app_highscores', methods: ['GET'])]
    public function index(Request $request, Highscores $highscores, \App\Game\GameStats $stats): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $query = $request->query;
        $view = \in_array($query->getString('view'), ['alliances', 'research'], true) ? $query->getString('view') : 'players';
        $sort = \in_array($query->getString('sort'), Highscores::SORTS, true) ? $query->getString('sort') : Highscores::BY_POINTS;
        $lifeForm = LifeForm::tryFrom($query->getString('lifeform'));

        $rows = 'alliances' === $view ? $highscores->alliances($sort, $lifeForm) : $highscores->players($sort, $lifeForm);
        // Research tab: the best three of every research.
        $research = 'research' === $view ? $highscores->researchLeaders($lifeForm) : [];
        // The own row (or own alliance) is always shown, even below the top 100.
        $isOwn = static fn (array $row): bool => 'alliances' === $view
            ? null !== $player->getAlliance() && $row['alliance']->getId() === $player->getAlliance()->getId()
            : $row['user']->isSameAs($player);
        $own = array_values(array_filter($rows, $isOwn))[0] ?? null;

        return $this->renderGame('game/highscores.html.twig', $planet, [
            'view' => $view,
            'sort' => $sort,
            'life_form' => $lifeForm,
            'rows' => \array_slice($rows, 0, self::TOP),
            'total' => \count($rows),
            'own' => $own,
            'own_outside' => null !== $own && $own['rank'] > self::TOP,
            'top' => self::TOP,
            'top_today' => $stats->topExperienceToday(),
            'research_leaders' => $research,
        ]);
    }
}
