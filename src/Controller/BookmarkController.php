<?php

namespace App\Controller;

use App\Entity\Bookmark;
use App\Game\BookmarkMove;
use App\Game\BookmarkService;
use App\Game\GameException;
use App\Repository\BookmarkRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** Remembered coordinates: added from the universe view, named and used for fleets. */
class BookmarkController extends AbstractGameController
{
    public function __construct(
        private readonly BookmarkService $service,
        private readonly BookmarkRepository $bookmarks,
    ) {
    }

    #[Route('/bookmarks', name: 'app_bookmarks', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $planet = $this->currentPlanet();

        return $this->renderGame('game/bookmarks.html.twig', $planet, [
            'bookmarks' => $this->bookmarks->findByOwner($this->player()),
            // Ticked boxes survive a move, so the same selection can be moved again.
            'selected' => array_fill_keys(self::ids($request->query->getString('selected')), true),
            'max_bookmarks' => BookmarkService::MAX_BOOKMARKS,
        ]);
    }

    #[Route('/bookmarks/add', name: 'app_bookmark_add', methods: ['POST'])]
    #[IsCsrfTokenValid('bookmark', tokenKey: '_token')]
    public function add(Request $request): Response
    {
        $this->currentPlanet();
        $form = $request->request;
        [$g, $s, $p] = [$form->getInt('galaxy'), $form->getInt('system'), $form->getInt('position')];
        $this->attempt(fn () => $this->service->add($this->player(), $g, $s, $p), t('Koordinaten [{g}:{s}:{p}] gemerkt.', ['{g}' => $g, '{s}' => $s, '{p}' => $p]));

        return $this->redirect($this->generateUrl('app_galaxy', ['galaxy' => $g, 'system' => $s]).'#slot-'.$p);
    }

    /** Moves the ticked bookmarks one place or all the way up or down; they stay ticked. */
    #[Route('/bookmarks/move', name: 'app_bookmark_move', methods: ['POST'])]
    #[IsCsrfTokenValid('bookmark', tokenKey: '_token')]
    public function move(Request $request): Response
    {
        $this->currentPlanet();
        $ids = self::ids(implode(',', $request->request->all('ids')));
        try {
            $move = BookmarkMove::tryFrom($request->request->getString('move')) ?? throw new GameException(t('Unbekannte Richtung.'));
            $this->service->move($this->player(), $ids, $move);
        } catch (GameException $e) {
            $this->addFlash('error', $e->translatable());
        }

        if ([] === $ids) {
            return $this->redirectToRoute('app_bookmarks');
        }

        return $this->redirect($this->generateUrl('app_bookmarks', ['selected' => implode(',', $ids)]).'#bookmark-'.$ids[0]);
    }

    /** Saves the whole order after drag & drop (called by game.js). */
    #[Route('/bookmarks/order', name: 'app_bookmark_order', methods: ['POST'])]
    #[IsCsrfTokenValid('bookmark', tokenKey: '_token')]
    public function order(Request $request): Response
    {
        $this->service->reorder($this->player(), self::ids(implode(',', $request->request->all('ids'))));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @return list<int> positive ids from "3,7,12", without duplicates */
    private static function ids(string $list): array
    {
        return array_values(array_unique(array_filter(array_map(intval(...), explode(',', $list)), static fn (int $id): bool => $id > 0)));
    }

    #[Route('/bookmarks/{id}/rename', name: 'app_bookmark_rename', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('bookmark', tokenKey: '_token')]
    public function rename(Bookmark $bookmark, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->rename($bookmark, $this->player(), $request->request->getString('label')), t('Name gespeichert.'));

        return $this->redirectToRoute('app_bookmarks');
    }

    #[Route('/bookmarks/{id}/delete', name: 'app_bookmark_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('bookmark', tokenKey: '_token')]
    public function delete(Bookmark $bookmark, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->remove($bookmark, $this->player()), t('[{coords}] vergessen.', ['{coords}' => $bookmark->getCoordinates()]));

        // Removing from the universe view goes back there.
        if ('galaxy' === $request->request->getString('from')) {
            return $this->redirect($this->generateUrl('app_galaxy', ['galaxy' => $bookmark->getGalaxy(), 'system' => $bookmark->getSystem()]).'#slot-'.$bookmark->getPosition());
        }

        return $this->redirectToRoute('app_bookmarks');
    }
}
