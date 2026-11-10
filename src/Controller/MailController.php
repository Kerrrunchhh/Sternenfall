<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Entity\PlayerMessage;
use App\Entity\User;
use App\Game\ContactService;
use App\Game\GameException;
use App\Game\Mailbox;
use App\Repository\ContactRepository;
use App\Repository\PlayerMessageRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** "Post": letters between players and the quick contact list. */
class MailController extends AbstractGameController
{
    public function __construct(
        private readonly Mailbox $mailbox,
        private readonly PlayerMessageRepository $letters,
        private readonly ContactService $contactService,
        private readonly ContactRepository $contacts,
    ) {
    }

    #[Route('/mail', name: 'app_mail', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        $sent = 'sent' === $request->query->getString('box');

        return $this->renderGame('game/mail.html.twig', $planet, [
            'box' => $sent ? 'sent' : 'inbox',
            'letters' => $sent ? $this->letters->findSent($player) : $this->letters->findInbox($player),
            'contacts' => $this->contactService->contactsOf($player),
            'incoming_requests' => $this->contacts->findIncomingRequests($player),
            'outgoing_requests' => $this->contacts->findOutgoingRequests($player),
        ]);
    }

    #[Route('/mail/new', name: 'app_mail_compose', methods: ['GET'])]
    public function compose(Request $request): Response
    {
        $planet = $this->currentPlanet();

        return $this->renderGame('game/mail_compose.html.twig', $planet, [
            'to' => $request->query->getString('to'),
            'subject' => '',
            'body' => '',
            'contacts' => $this->contactService->contactsOf($this->player()),
        ]);
    }

    #[Route('/mail/send', name: 'app_mail_send', methods: ['POST'])]
    #[IsCsrfTokenValid('mail', tokenKey: '_token')]
    public function send(Request $request): Response
    {
        $planet = $this->currentPlanet();
        $form = $request->request;
        try {
            $letter = $this->mailbox->send($this->player(), $form->getString('to'), $form->getString('subject'), $form->getString('body'));
        } catch (GameException $e) {
            // Keep what was written.
            $this->addFlash('error', $e->getMessage());

            return $this->renderGame('game/mail_compose.html.twig', $planet, [
                'to' => $form->getString('to'),
                'subject' => $form->getString('subject'),
                'body' => $form->getString('body'),
                'contacts' => $this->contactService->contactsOf($this->player()),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        $this->addFlash('success', \sprintf('Nachricht an %s gesendet.', $letter->getRecipient()?->getUsername()));

        return $this->redirectToRoute('app_mail', ['box' => 'sent']);
    }

    #[Route('/mail/{id}', name: 'app_mail_show', requirements: ['id' => Requirement::DIGITS], methods: ['GET'])]
    public function show(PlayerMessage $letter): Response
    {
        $planet = $this->currentPlanet();
        $player = $this->player();
        try {
            $this->mailbox->open($letter, $player);
        } catch (GameException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_mail');
        }
        $received = $letter->isReceivedBy($player);
        [$subject, $quote] = Mailbox::replyDraft($letter);

        return $this->renderGame('game/mail_show.html.twig', $planet, [
            'letter' => $letter,
            'received' => $received,
            'reply_to' => $received ? $letter->getSender() : $letter->getRecipient(),
            'reply_subject' => $subject,
            'reply_body' => $quote,
        ]);
    }

    #[Route('/mail/delete', name: 'app_mail_delete', methods: ['POST'])]
    #[IsCsrfTokenValid('mail', tokenKey: '_token')]
    public function delete(Request $request): Response
    {
        $this->currentPlanet();
        $ids = array_values(array_map('intval', array_filter($request->request->all('letters'), static fn ($id): bool => \is_string($id) && ctype_digit($id))));
        $count = $this->mailbox->delete($this->letters->findByIds($ids), $this->player());
        $this->addFlash($count > 0 ? 'success' : 'error', match ($count) {
            0 => 'Keine Nachricht ausgewählt.',
            1 => 'Nachricht gelöscht.',
            default => \sprintf('%d Nachrichten gelöscht.', $count),
        });
        $box = $request->request->getString('box');

        return $this->redirectToRoute('app_mail', 'sent' === $box ? ['box' => 'sent'] : []);
    }

    #[Route('/contacts/request/{id}', name: 'app_contact_request', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('contact', tokenKey: '_token')]
    public function requestContact(User $other): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->contactService->request($this->player(), $other), \sprintf('Kontaktanfrage an %s gesendet – oder seine Anfrage angenommen.', $other->getUsername()));

        return $this->redirectToRoute('app_player', ['id' => $other->getId()]);
    }

    #[Route('/contacts/{id}/accept', name: 'app_contact_accept', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('contact', tokenKey: '_token')]
    public function acceptContact(Contact $contact, Request $request): Response
    {
        $this->currentPlanet();
        $this->attempt(fn () => $this->contactService->accept($contact, $this->player()), \sprintf('%s steht jetzt in deinen Schnellkontakten.', $contact->getRequester()->getUsername()));

        return $this->backTo($request, 'app_mail');
    }

    #[Route('/contacts/{id}/remove', name: 'app_contact_remove', requirements: ['id' => Requirement::DIGITS], methods: ['POST'])]
    #[IsCsrfTokenValid('contact', tokenKey: '_token')]
    public function removeContact(Contact $contact, Request $request): Response
    {
        $this->currentPlanet();
        $player = $this->player();
        $message = match (true) {
            $contact->isAccepted() => \sprintf('%s aus den Schnellkontakten entfernt.', $contact->other($player)->getUsername()),
            $contact->getRequester()->isSameAs($player) => 'Anfrage zurückgezogen.',
            default => 'Anfrage abgelehnt.',
        };
        $this->attempt(fn () => $this->contactService->remove($contact, $player), $message);

        return $this->backTo($request, 'app_mail');
    }
}
