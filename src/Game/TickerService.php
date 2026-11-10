<?php

namespace App\Game;

use App\Entity\Ticker;
use App\Entity\TickerMember;
use App\Entity\TickerPost;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function Symfony\Component\Translation\t;

/**
 * Long-range tickers: anybody opens one; only the owner invites and removes players,
 * deletes posts and hands the ticker over. Invited players must accept first.
 */
final readonly class TickerService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private Inbox $inbox,
        private ClockInterface $clock,
    ) {
    }

    public function open(User $owner, string $name, string $description = ''): Ticker
    {
        $name = trim($name);
        if ('' === $name) {
            throw new GameException(t('Der Ticker braucht einen Namen.'));
        }
        if (mb_strlen($name) > Ticker::MAX_NAME) {
            throw new GameException(t('Der Name darf höchstens {value} Zeichen lang sein.', ['{value}' => Ticker::MAX_NAME]));
        }
        $ticker = new Ticker($name, $owner, $this->now());
        $ticker->describe($description);
        $this->em->persist($ticker);
        $this->em->flush();

        return $ticker;
    }

    public function invite(Ticker $ticker, User $owner, string $username): User
    {
        $this->assertOwner($ticker, $owner);
        $username = trim($username);
        $user = '' === $username ? null : $this->users->findOneBy(['username' => $username]);
        if (null === $user) {
            throw new GameException(t('Einen Spieler „{username}“ gibt es nicht.', ['{username}' => $username]));
        }
        $existing = $ticker->membership($user);
        if (null !== $existing) {
            throw new GameException($existing->isAccepted()
                ? t('{player} ist schon im Ticker.', ['{player}' => $user->getUsername()])
                : t('{player} ist schon eingeladen.', ['{player}' => $user->getUsername()]));
        }
        $ticker->invite($user, $this->now());
        $about = '' === $ticker->getDescription() ? '' : t("\n\nWorum es geht:\n{description}", ['{description}' => $ticker->getDescription()]);
        $this->inbox->send($user, t('Einladung in den Ticker „{name}“', ['{name}' => $ticker->getName()]), t('{player} lädt dich in den Langstreckenticker „{name}“ ein. Annehmen oder ablehnen kannst du unter Ticker.{about}', ['{player}' => $owner->getUsername(), '{name}' => $ticker->getName(), '{about}' => $about]));
        $this->em->flush();

        return $user;
    }

    public function accept(TickerMember $invitation, User $user): void
    {
        if (!$invitation->getUser()->isSameAs($user) || $invitation->isAccepted()) {
            throw new GameException(t('Diese Einladung gibt es nicht (mehr).'));
        }
        $invitation->accept($this->now());
        $this->em->flush();
    }

    /** Declines an invitation or leaves a ticker. The owner has to hand it over or close it first. */
    public function leave(TickerMember $membership, User $user): void
    {
        $ticker = $membership->getTicker();
        if (!$membership->getUser()->isSameAs($user)) {
            throw new GameException(t('Das ist nicht deine Mitgliedschaft.'));
        }
        if ($ticker->isOwnedBy($user)) {
            throw new GameException(t('Als Besitzer kannst du den Ticker nicht verlassen. Gib ihn vorher an ein Mitglied ab oder schließe ihn.'));
        }
        $ticker->removeMember($membership);
        $this->em->flush();
    }

    /** The owner removes a member or withdraws an invitation. */
    public function remove(Ticker $ticker, User $owner, TickerMember $member): void
    {
        $this->assertOwner($ticker, $owner);
        if ($member->getTicker() !== $ticker || $member->getUser()->isSameAs($owner)) {
            throw new GameException(t('Diesen Spieler kannst du nicht entfernen.'));
        }
        $ticker->removeMember($member);
        if ($member->isAccepted()) {
            $this->inbox->send($member->getUser(), t('Aus dem Ticker „{name}“ entfernt', ['{name}' => $ticker->getName()]), t('{player} hat dich aus dem Langstreckenticker „{name}“ entfernt.', ['{player}' => $owner->getUsername(), '{name}' => $ticker->getName()]));
        }
        $this->em->flush();
    }

    public function handOver(Ticker $ticker, User $owner, TickerMember $member): void
    {
        $this->assertOwner($ticker, $owner);
        if ($member->getTicker() !== $ticker || !$member->isAccepted() || $member->getUser()->isSameAs($owner)) {
            throw new GameException(t('Abgeben kannst du den Ticker nur an ein anderes Mitglied.'));
        }
        $ticker->handOver($member->getUser());
        $this->inbox->send($member->getUser(), t('Ticker „{name}“ übernommen', ['{name}' => $ticker->getName()]), t('{player} hat dir den Langstreckenticker „{name}“ übergeben. Du kannst jetzt Spieler einladen und entfernen und Beiträge löschen.', ['{player}' => $owner->getUsername(), '{name}' => $ticker->getName()]));
        $this->em->flush();
    }

    public function describe(Ticker $ticker, User $owner, string $description): void
    {
        $this->assertOwner($ticker, $owner);
        $ticker->describe($description);
        $this->em->flush();
    }

    public function post(Ticker $ticker, User $author, string $body): TickerPost
    {
        if (!$ticker->isMember($author)) {
            throw new GameException(t('Nur Mitglieder können in diesem Ticker schreiben.'));
        }
        $body = trim($body);
        if ('' === $body) {
            throw new GameException(t('Der Beitrag ist leer.'));
        }
        if (mb_strlen($body) > TickerPost::MAX_BODY) {
            throw new GameException(t('Ein Beitrag darf höchstens {value} Zeichen lang sein.', ['{value}' => TickerPost::MAX_BODY]));
        }
        $post = new TickerPost($ticker, $author, $body, $this->now());
        $this->em->persist($post);
        $this->em->flush();

        return $post;
    }

    public function deletePost(TickerPost $post, User $owner): void
    {
        $this->assertOwner($post->getTicker(), $owner);
        $this->em->remove($post);
        $this->em->flush();
    }

    /** Closes the ticker for good, posts included. */
    public function close(Ticker $ticker, User $owner): void
    {
        $this->assertOwner($ticker, $owner);
        $this->em->remove($ticker);
        $this->em->flush();
    }

    private function assertOwner(Ticker $ticker, User $user): void
    {
        if (!$ticker->isOwnedBy($user)) {
            throw new GameException(t('Das darf nur der Besitzer des Tickers.'));
        }
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
