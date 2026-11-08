<?php

namespace App\Controller;

use App\Entity\Bookmark;
use App\Game\BookmarkService;
use App\Repository\BookmarkRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** Remembered coordinates: added from the universe view, named and used for fleets. */
class BookmarkController extends AbstractGameController
{
    public function __construct(
        private readonly BookmarkService $service,
        private readonly BookmarkRepository $bookmarks,
    ) {
    }

    #[Route('/bookmarks', name: 'app_bookmarks', methods: ['GET'])]
    public function index(): Response
    {
        $planet = $this->currentPlanet();

        return $this->renderGame('game/bookmarks.html.twig', $planet, [
            'bookmarks' => $this->bookmarks->findByOwner($this->player()),
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
        $this->attempt(fn () => $this->service->add($this->player(), $g, $s, $p), \sprintf('Koordinaten [%d:%d:%d] gemerkt.', $g, $s, $p));

        return $this->redirect($this->generateUrl('app_galaxy', ['galaxy' => $g, 'system' => $s]).'#slot-'.$p);
    }

    #[Route('/bookmarks/{id}/rename', name: 'app_bookmark_rename', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('bookmark', tokenKey: '_token')]
    public function rename(Bookmark $bookmark, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->rename($bookmark, $this->player(), $request->request->getString('label')), 'Name gespeichert.');

        return $this->redirectToRoute('app_bookmarks');
    }

    #[Route('/bookmarks/{id}/delete', name: 'app_bookmark_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('bookmark', tokenKey: '_token')]
    public function delete(Bookmark $bookmark, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->service->remove($bookmark, $this->player()), \sprintf('[%s] vergessen.', $bookmark->getCoordinates()));

        // Removing from the universe view goes back there.
        if ('galaxy' === $request->request->getString('from')) {
            return $this->redirect($this->generateUrl('app_galaxy', ['galaxy' => $bookmark->getGalaxy(), 'system' => $bookmark->getSystem()]).'#slot-'.$bookmark->getPosition());
        }

        return $this->redirectToRoute('app_bookmarks');
    }
}
