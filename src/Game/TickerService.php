<?php

namespace App\Game;

use App\Entity\Ticker;
use App\Entity\TickerMember;
use App\Entity\TickerPost;
use App\Entity\User;
use App\I18n\When;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function Symfony\Component\Translation\t;

/**
 * Long-range tickers: anybody opens one. The owner and the admins he appoints invite, remove
 * and mute players and delete posts; only the owner appoints admins and a deputy, describes,
 * hands over and closes the ticker. Invited players must accept first. The deputy takes over
 * when the owner hasn't been online for Ticker::DEPUTY_AFTER_DAYS.
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

    /** Mute durations offered, in days. */
    public const array MUTE_DAYS = [1, 3, 7, 30];

    public function invite(Ticker $ticker, User $owner, string $username): User
    {
        $this->assertModerator($ticker, $owner);
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

    /** The owner or an admin removes a member or withdraws an invitation (admins: plain members only). */
    public function remove(Ticker $ticker, User $owner, TickerMember $member): void
    {
        $this->assertModerator($ticker, $owner);
        if (!$ticker->canModerateMember($owner, $member)) {
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
        $membership = $ticker->membership($author);
        if (null !== $membership && $membership->isMuted($this->now())) {
            throw new GameException(t('Du bist in diesem Ticker bis {until} stummgeschaltet.', ['{until}' => new When($membership->getMutedUntil())]));
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

    /** Only the author changes his post, as long as he still belongs to the ticker. */
    public function editPost(TickerPost $post, User $author, string $body): void
    {
        if (!$post->isWrittenBy($author) || !$post->getTicker()->isMember($author)) {
            throw new GameException(t('Nur eigene Beiträge kannst du bearbeiten.'));
        }
        $body = trim($body);
        if ('' === $body) {
            throw new GameException(t('Der Beitrag ist leer.'));
        }
        if (mb_strlen($body) > TickerPost::MAX_BODY) {
            throw new GameException(t('Ein Beitrag darf höchstens {value} Zeichen lang sein.', ['{value}' => TickerPost::MAX_BODY]));
        }
        $post->edit($body, $this->now());
        $this->em->flush();
    }

    /** Owner: any post; admins: those of plain members and their own. */
    public function deletePost(TickerPost $post, User $owner): void
    {
        $ticker = $post->getTicker();
        $this->assertModerator($ticker, $owner);
        if (!$ticker->canDeletePost($owner, $post)) {
            throw new GameException(t('Beiträge des Besitzers und anderer Admins kannst du nicht löschen.'));
        }
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

    /** Owner or admin mutes a member for some days; 0 days lifts the mute. */
    public function mute(Ticker $ticker, User $moderator, TickerMember $member, int $days): void
    {
        $this->assertModerator($ticker, $moderator);
        if (!$member->isAccepted() || !$ticker->canModerateMember($moderator, $member)) {
            throw new GameException(t('Diesen Spieler kannst du nicht stummschalten.'));
        }
        if (0 === $days) {
            $member->muteUntil(null);
            $this->em->flush();

            return;
        }
        if (!\in_array($days, self::MUTE_DAYS, true)) {
            throw new GameException(t('Diese Dauer gibt es nicht.'));
        }
        $until = $this->now()->modify(\sprintf('+%d days', $days));
        $member->muteUntil($until);
        $this->inbox->send($member->getUser(), t('Im Ticker „{name}“ stummgeschaltet', ['{name}' => $ticker->getName()]), t('{player} hat dich im Langstreckenticker „{name}“ bis {until} stummgeschaltet. Lesen kannst du weiter, schreiben erst danach wieder.', ['{player}' => $moderator->getUsername(), '{name}' => $ticker->getName(), '{until}' => new When($until)]));
        $this->em->flush();
    }

    /** The owner appoints a member as admin or takes the rights back. */
    public function setAdmin(Ticker $ticker, User $owner, TickerMember $member, bool $admin): void
    {
        $this->assertOwner($ticker, $owner);
        if ($member->getTicker() !== $ticker || !$member->isAccepted() || $ticker->isOwnedBy($member->getUser())) {
            throw new GameException(t('Admin werden kann nur ein anderes Mitglied.'));
        }
        $member->setAdmin($admin);
        if ($admin) {
            $member->muteUntil(null);
            $this->inbox->send($member->getUser(), t('Admin im Ticker „{name}“', ['{name}' => $ticker->getName()]), t('{player} hat dich zum Admin des Langstreckentickers „{name}“ ernannt. Du kannst jetzt Spieler einladen, entfernen und stummschalten und Beiträge löschen.', ['{player}' => $owner->getUsername(), '{name}' => $ticker->getName()]));
        }
        $this->em->flush();
    }

    /** The owner names a member as deputy (null: none). */
    public function setDeputy(Ticker $ticker, User $owner, ?TickerMember $member): void
    {
        $this->assertOwner($ticker, $owner);
        if (null === $member) {
            $ticker->setDeputy(null);
            $this->em->flush();

            return;
        }
        if ($member->getTicker() !== $ticker || !$member->isAccepted() || $ticker->isOwnedBy($member->getUser())) {
            throw new GameException(t('Stellvertreter kann nur ein anderes Mitglied werden.'));
        }
        $ticker->setDeputy($member->getUser());
        $this->inbox->send($member->getUser(), t('Stellvertreter im Ticker „{name}“', ['{name}' => $ticker->getName()]), t('{player} hat dich zum Stellvertreter des Langstreckentickers „{name}“ ernannt. Ist {player} {days} Tage nicht online, wirst du automatisch Besitzer.', ['{player}' => $owner->getUsername(), '{name}' => $ticker->getName(), '{days}' => Ticker::DEPUTY_AFTER_DAYS]));
        $this->em->flush();
    }

    /**
     * The deputy takes over when the owner hasn't been online for Ticker::DEPUTY_AFTER_DAYS
     * (checked whenever the ticker is opened). Returns whether the owner changed.
     */
    public function succeedIfAbandoned(Ticker $ticker): bool
    {
        $deputy = $ticker->getDeputy();
        if (null === $deputy || !$ticker->isMember($deputy)) {
            return false;
        }
        $owner = $ticker->getOwner();
        $lastSeen = $owner->getLastSeenAt() ?? $ticker->getCreatedAt();
        $limit = $this->now()->modify(\sprintf('-%d days', Ticker::DEPUTY_AFTER_DAYS));
        if ($lastSeen > $limit) {
            return false;
        }
        $ticker->handOver($deputy);
        $this->inbox->send($deputy, t('Ticker „{name}“ übernommen', ['{name}' => $ticker->getName()]), t('{player} war {days} Tage nicht online. Als Stellvertreter bist du jetzt Besitzer des Langstreckentickers „{name}“.', ['{player}' => $owner->getUsername(), '{name}' => $ticker->getName(), '{days}' => Ticker::DEPUTY_AFTER_DAYS]));
        $this->inbox->send($owner, t('Ticker „{name}“ abgegeben', ['{name}' => $ticker->getName()]), t('Du warst {days} Tage nicht online. Dein Stellvertreter {player} ist jetzt Besitzer des Langstreckentickers „{name}“; du bleibst Mitglied.', ['{player}' => $deputy->getUsername(), '{name}' => $ticker->getName(), '{days}' => Ticker::DEPUTY_AFTER_DAYS]));
        $this->em->flush();

        return true;
    }

    private function assertModerator(Ticker $ticker, User $user): void
    {
        if (!$ticker->canModerate($user)) {
            throw new GameException(t('Das dürfen nur der Besitzer und die Admins des Tickers.'));
        }
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
