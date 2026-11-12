<?php

namespace App\Controller;

use App\Entity\News;
use App\Form\NewsType;
use App\Game\NewsService;
use App\Repository\NewsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

use function Symfony\Component\Translation\t;

/** News: every player reads them (and finds read ones again here), admins write them. */
class NewsController extends AbstractGameController
{
    public function __construct(
        private readonly NewsService $service,
        private readonly NewsRepository $news,
    ) {
    }

    #[Route('/neuigkeiten', name: 'app_news', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $planet = $this->currentPlanet();
        $form = null;
        if ($this->isGranted('ROLE_ADMIN')) {
            $form = $this->createForm(NewsType::class, $this->service->create(), ['action' => $this->generateUrl('app_news')]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $this->service->save($form->getData());
                $this->addFlash('success', t('Neuigkeit veröffentlicht.'));

                return $this->redirectToRoute('app_news');
            }
        }

        return $this->renderGame('game/news.html.twig', $planet, [
            'all_news' => $this->news->findNewestFirst(),
            'read_ids' => $this->news->readIds($this->player()),
            'form' => $form,
        ]);
    }

    #[Route('/neuigkeiten/{id}/bearbeiten', name: 'app_news_edit', requirements: ['id' => Requirement::DIGITS], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(News $item, Request $request): Response
    {
        $planet = $this->currentPlanet();
        $form = $this->createForm(NewsType::class, $item);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->service->save($item);
            $this->addFlash('success', t('Neuigkeit gespeichert.'));

            return $this->redirectToRoute('app_news');
        }

        return $this->renderGame('game/news_edit.html.twig', $planet, ['item' => $item, 'form' => $form]);
    }

    #[Route('/neuigkeiten/{id}/loeschen', name: 'app_news_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('news', tokenKey: '_token')]
    public function delete(News $item): Response
    {
        $this->service->delete($item);
        $this->addFlash('success', t('Neuigkeit gelöscht.'));

        return $this->redirectToRoute('app_news');
    }

    #[Route('/neuigkeiten/{id}/gelesen', name: 'app_news_read', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('news', tokenKey: '_token')]
    public function read(News $item, Request $request): Response
    {
        $this->service->markRead($this->player(), $item);

        return $this->backTo($request, 'app_news');
    }

    #[Route('/neuigkeiten/alle-gelesen', name: 'app_news_read_all', methods: ['POST'])]
    #[IsCsrfTokenValid('news', tokenKey: '_token')]
    public function readAll(Request $request): Response
    {
        $this->service->markAllRead($this->player());

        return $this->backTo($request, 'app_news');
    }
}
