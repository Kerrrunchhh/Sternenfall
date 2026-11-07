<?php

namespace App\Controller;

use App\Repository\MessageRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MessageController extends AbstractGameController
{
    #[Route('/messages', name: 'app_messages', methods: ['GET'])]
    public function index(MessageRepository $messages): Response
    {
        $planet = $this->currentPlanet();
        $list = $messages->findLatest($this->player());
        // Render first (so new messages are highlighted), then mark everything as read.
        $response = $this->renderGame('game/messages.html.twig', $planet, ['messages' => $list]);
        $messages->markAllRead($this->player());

        return $response;
    }
}
