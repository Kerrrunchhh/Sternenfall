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

        // Folders: battle reports, espionage, general – or all of them.
        $folder = \App\Game\MessageFolder::tryFrom($request->query->getString('folder'));

        // Many messages are split into pages of 50.
        $total = $this->messages->countFor($this->player(), $folder);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, ctype_digit($p = $request->query->getString('page')) ? (int) $p : 1));

        return $this->renderGame('game/messages.html.twig', $planet, [
            'messages' => $this->messages->findLatest($this->player(), self::PER_PAGE, ($page - 1) * self::PER_PAGE, $folder),
            'folder' => $folder,
            'folders' => \App\Game\MessageFolder::cases(),
            'folder_unread' => $this->messages->countUnreadByFolder($this->player()),
            'keep_days' => MessageRepository::KEEP_DAYS,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
        ]);
    }

    #[Route('/messages/{id}/delete', name: 'app_message_delete', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('messages', tokenKey: '_token')]
    public function delete(Message $message, EntityManagerInterface $em, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        $this->currentPlanet();
        if ($message->getRecipient()->isSameAs($this->player())) {
            $em->remove($message);
            $em->flush();
            $this->addFlash('success', t('Nachricht gelöscht.'));
        }

        return $this->redirectToRoute('app_messages', self::back($request));
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

        return new JsonResponse(['unread' => $this->messages->countUnread($player), 'folders' => $this->messages->countUnreadByFolder($player)]);
    }

    #[Route('/messages/read-all', name: 'app_messages_read_all', methods: ['POST'])]
    #[IsCsrfTokenValid('messages', tokenKey: '_token')]
    public function readAll(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $this->currentPlanet();
        $folder = \App\Game\MessageFolder::tryFrom($request->request->getString('folder'));
        $this->messages->markAllRead($this->player(), $folder);
        $this->addFlash('success', null === $folder ? t('Alle Nachrichten als gelesen markiert.') : t('Alle Nachrichten in „{folder}“ als gelesen markiert.', ['{folder}' => t($folder->label())]));

        return $this->redirectToRoute('app_messages', self::back($request));
    }

    /** @return array<string, string> the folder (and page) to go back to after an action */
    private static function back(\Symfony\Component\HttpFoundation\Request $request): array
    {
        $folder = \App\Game\MessageFolder::tryFrom($request->request->getString('folder'));
        $page = $request->request->getString('page');

        return array_filter(['folder' => $folder?->value, 'page' => ctype_digit($page) && '1' !== $page ? $page : null]);
    }
}
