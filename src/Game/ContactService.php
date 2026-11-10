<?php

namespace App\Game;

use App\Entity\Contact;
use App\Entity\User;
use App\Repository\ContactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Quick contacts: a player asks, the other accepts; then both have each other in their
 * list on the overview and see whether the other is online.
 */
final readonly class ContactService
{
    public const int MAX_CONTACTS = 50;

    public function __construct(
        private EntityManagerInterface $em,
        private ContactRepository $contacts,
        private Inbox $inbox,
        private ClockInterface $clock,
    ) {
    }

    public function request(User $user, User $other): Contact
    {
        if ($other->isSameAs($user)) {
            throw new GameException('Du kannst dich nicht selbst als Schnellkontakt aufnehmen.');
        }
        $existing = $this->contacts->findBetween($user, $other);
        if (null !== $existing) {
            if ($existing->isAccepted()) {
                throw new GameException(\sprintf('%s ist schon in deinen Schnellkontakten.', $other->getUsername()));
            }
            if ($existing->getRequester()->isSameAs($user)) {
                throw new GameException(\sprintf('Du hast %s schon angefragt.', $other->getUsername()));
            }
            // The other asked first: asking back accepts.
            $this->accept($existing, $user);

            return $existing;
        }
        $this->assertRoom($user);

        $contact = new Contact($user, $other, $this->now());
        $this->em->persist($contact);
        $this->inbox->send($other, 'Kontaktanfrage von '.$user->getUsername(), \sprintf('%s möchte dich in die Schnellkontakte aufnehmen. Annehmen oder ablehnen kannst du in der Übersicht oder unter Post.', $user->getUsername()));
        $this->em->flush();

        return $contact;
    }

    public function accept(Contact $contact, User $user): void
    {
        if (!$contact->getAddressee()->isSameAs($user) || $contact->isAccepted()) {
            throw new GameException('Diese Anfrage gibt es nicht (mehr).');
        }
        $this->assertRoom($user);
        $this->assertRoom($contact->getRequester());
        $contact->accept($this->now());
        $this->inbox->send($contact->getRequester(), 'Kontaktanfrage angenommen', \sprintf('%s hat deine Anfrage angenommen und steht jetzt in deinen Schnellkontakten.', $user->getUsername()));
        $this->em->flush();
    }

    /** Declines a request, withdraws an own request or removes an accepted contact (for both players). */
    public function remove(Contact $contact, User $user): void
    {
        if (!$contact->involves($user)) {
            throw new GameException('Diesen Kontakt gibt es nicht (mehr).');
        }
        $this->em->remove($contact);
        $this->em->flush();
    }

    /** @return list<User> the player's quick contacts, sorted by name */
    public function contactsOf(User $user): array
    {
        $others = array_map(static fn (Contact $c): User => $c->other($user), $this->contacts->findAccepted($user));
        usort($others, static fn (User $a, User $b): int => strcasecmp($a->getUsername(), $b->getUsername()));

        return $others;
    }

    private function assertRoom(User $user): void
    {
        if (\count($this->contacts->findAccepted($user)) >= self::MAX_CONTACTS) {
            throw new GameException(\sprintf('%s hat schon %d Schnellkontakte – mehr gehen nicht.', $user->getUsername(), self::MAX_CONTACTS));
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
