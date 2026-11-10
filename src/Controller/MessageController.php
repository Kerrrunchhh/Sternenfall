<?php

namespace App\Controller;

use App\Entity\Message;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

use function Symfony\Component\Translation\t;

/** System messages (fleet, combat and spy reports): each one counts as read once opened. */
class MessageController extends AbstractGameController
{
    public function __construct(private readonly MessageRepository $messages)
    {
    }

    public const int PER_PAGE = 50;

    #[Route('/messages', name: 'app_messages', methods: ['GET'])]
    public function index(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $planet = $this->currentPlanet();
        // Messages older than 30 days are removed (for everybody).
        $this->messages->deleteOlderThan(\DateTimeImmutable::createFromInterface($this->clock->now())->modify('-'.MessageRepository::KEEP_DAYS.' days'));

        // Many messages are split into pages of 50.
        $total = $this->messages->countFor($this->player());
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, ctype_digit($p = $request->query->getString('page')) ? (int) $p : 1));

        return $this->renderGame('game/messages.html.twig', $planet, [
            'messages' => $this->messages->findLatest($this->player(), self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'keep_days' => MessageRepository::KEEP_DAYS,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    #[Route('/messages/{id}/delete', name: 'app_message_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('messages', tokenKey: '_token')]
    public function delete(Message $message, EntityManagerInterface $em): Response
    {
        $this->currentPlanet();
        if ($message->getRecipient()->isSameAs($this->player())) {
            $em->remove($message);
            $em->flush();
            $this->addFlash('success', t('Nachricht gelöscht.'));
        }

        return $this->redirectToRoute('app_messages');
    }

    /** Opening a message marks it read; answers with the remaining unread count for the badge. */
    #[Route('/messages/{id}/read', name: 'app_message_read', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('messages', tokenKey: '_token')]
    public function read(Message $message, EntityManagerInterface $em): JsonResponse
    {
        $player = $this->player();
        if ($message->getRecipient()->isSameAs($player) && !$message->isRead()) {
            $message->markRead();
            $em->flush();
        }

        return new JsonResponse(['unread' => $this->messages->countUnread($player)]);
    }

    #[Route('/messages/read-all', name: 'app_messages_read_all', methods: ['POST'])]
    #[IsCsrfTokenValid('messages', tokenKey: '_token')]
    public function readAll(): Response
    {
        $this->currentPlanet();
        $this->messages->markAllRead($this->player());
        $this->addFlash('success', t('Alle Nachrichten als gelesen markiert.'));

        return $this->redirectToRoute('app_messages');
    }
}
