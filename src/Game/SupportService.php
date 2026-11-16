<?php

namespace App\Game;

use App\I18n\Lines;
use App\Entity\Ticket;
use App\Entity\TicketComment;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

use function Symfony\Component\Translation\t;

/**
 * Reports about players, suggestions and bug reports. Admins ask questions, accept or
 * reject them, forward them to the developer or – for reports – impose a penalty
 * (usually 1–3 days of vacation).
 */
final readonly class SupportService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private Inbox $inbox,
        private VacationService $vacations,
        private MailerInterface $mailer,
        private ClockInterface $clock,
        private ?\Symfony\Component\Routing\Generator\UrlGeneratorInterface $urls = null,
        #[Autowire(env: 'DEVELOPER_EMAIL')]
        private string $developerEmail = '',
        #[Autowire(env: 'MAILER_FROM')]
        private string $mailFrom = 'noreply@example.org',
    ) {
    }

    public function create(User $author, TicketKind $kind, string $subject, string $body, string $reportedName = ''): Ticket
    {
        $subject = trim($subject);
        $body = trim($body);
        if ('' === $subject || '' === $body) {
            throw new GameException(t('Bitte gib einen Betreff und eine Beschreibung an.'));
        }
        if (!\in_array($kind, TicketKind::forPlayers(), true)) {
            throw new GameException(t('Diese Art von Meldung kannst du nicht schicken.'));
        }
        $reported = null;
        if (TicketKind::Report === $kind) {
            $reported = $this->users->findOneBy(['username' => trim($reportedName)]);
            if (null === $reported) {
                throw new GameException(t('Einen Spieler „{value}“ gibt es nicht.', ['{value}' => trim($reportedName)]));
            }
            if ($reported->isSameAs($author)) {
                throw new GameException(t('Dich selbst kannst du nicht melden.'));
            }
        }
        $ticket = new Ticket($kind, $author, $reported, $subject, $body, $this->now());
        $this->em->persist($ticket);
        // The id is needed for the button to the ticket.
        $this->em->flush();
        foreach ($this->admins() as $admin) {
            $this->inbox->send($admin, t('Neue Meldung: {icon} {kind}', ['{icon}' => $kind->icon(), '{kind}' => t($kind->label())]), t('{player} hat eine Meldung geschickt: „{subject}“. Bearbeiten im Adminbereich unter Meldungen.', ['{player}' => $author->getUsername(), '{subject}' => $ticket->getSubject()]), ...$this->teamButton($ticket));
        }
        $this->em->flush();

        return $ticket;
    }

    /**
     * A moderator asks a player about a possible multi account. The player sees the message from
     * "the support team" (no name, not the other account) and can explain himself in the dialog.
     *
     * @param User|null $other the account it shares an IP or device with (kept for the team only)
     */
    public function askAboutMulti(User $player, ?User $other, string $subject, string $body): Ticket
    {
        $subject = mb_substr(trim($subject), 0, Ticket::MAX_SUBJECT);
        $body = trim($body);
        if ('' === $subject || '' === $body) {
            throw new GameException(t('Bitte gib einen Betreff und eine Beschreibung an.'));
        }
        $ticket = new Ticket(TicketKind::MultiCheck, $player, $other, $subject, $body, $this->now());
        // Waiting for the player's answer.
        $ticket->setStatus(TicketStatus::Question, $this->now());
        $this->em->persist($ticket);
        $this->em->flush();
        $this->inbox->send($player, t('Nachricht vom Support-Team: {subject}', ['{subject}' => $subject]), new Lines([
            t('Das Support-Team hat eine Frage zu deinem Account. Bitte antworte unter Hilfe & Meldungen.'),
            '',
            $body,
        ]), ...$this->playerButton($ticket));
        $this->em->flush();

        return $ticket;
    }

    /** The author answers a question of the admins (or adds something). */
    public function reply(Ticket $ticket, User $author, string $body): void
    {
        if (!$ticket->isByAuthor($author)) {
            throw new GameException(t('Das ist nicht deine Meldung.'));
        }
        $body = $this->text($body);
        $ticket->comment($author, $body, false, $this->now());
        if (TicketStatus::Question === $ticket->getStatus()) {
            $ticket->setStatus(TicketStatus::Open, $this->now());
        }
        foreach ($this->admins() as $admin) {
            $this->inbox->send($admin, t('Antwort auf Meldung: {subject}', ['{subject}' => $ticket->getSubject()]), t('{player} hat geantwortet. Bearbeiten im Adminbereich unter Meldungen.', ['{player}' => $author->getUsername()]), ...$this->teamButton($ticket));
        }
        $this->em->flush();
    }

    /** An admin writes (a question if $status is Question) and/or changes the status. */
    public function answer(Ticket $ticket, User $admin, string $body, TicketStatus $status): void
    {
        $body = trim($body);
        if ('' === $body && $status === $ticket->getStatus()) {
            throw new GameException(t('Schreib eine Nachricht oder ändere den Status.'));
        }
        if (TicketStatus::Question === $status && '' === $body) {
            throw new GameException(t('Bei einer Rückfrage gehört die Frage dazu.'));
        }
        if ('' !== $body) {
            $ticket->comment($admin, $body, true, $this->now());
        }
        $ticket->setStatus($status, $this->now());
        $this->notifyAuthor($ticket, $body);
        $this->em->flush();
    }

    /**
     * A team member corrects a message of the team: his own answers, as an admin every team
     * answer, and the opening message of a ticket the team started. The player is not notified.
     */
    public function editTeamMessage(Ticket $ticket, ?TicketComment $comment, User $editor, string $body): void
    {
        if (!$this->mayEdit($ticket, $comment, $editor)) {
            throw new GameException(t('Diese Nachricht darfst du nicht bearbeiten.'));
        }
        $body = $this->text($body);
        if (null === $comment) {
            $ticket->editBody($body, $this->now());
        } else {
            $comment->edit($body, $this->now());
        }
        $this->em->flush();
    }

    /** Whether a team member may change that message (null comment: the ticket's opening message). */
    public static function mayEdit(Ticket $ticket, ?TicketComment $comment, User $editor): bool
    {
        $roles = $editor->getRoles();
        $admin = \in_array('ROLE_ADMIN', $roles, true);
        if (!$admin && !\in_array('ROLE_MODERATOR', $roles, true)) {
            return false;
        }
        if (null === $comment) {
            return $ticket->getKind()->isFromTeam();
        }

        return $comment->getTicket() === $ticket && $comment->isFromAdmin()
            && ($admin || (null !== $comment->getAuthor() && $comment->getAuthor()->isSameAs($editor)));
    }

    /** Hands a suggestion or bug report to the developer (by e-mail, if configured). */
    public function forward(Ticket $ticket, User $admin, string $note): void
    {
        $note = trim($note);
        $ticket->comment($admin, 'An den Entwickler weitergeleitet.'.('' === $note ? '' : "\n".$note), true, $this->now());
        $ticket->setStatus(TicketStatus::Forwarded, $this->now());
        if ('' !== $this->developerEmail) {
            $this->mailer->send((new Email())
                ->from($this->mailFrom)
                ->to($this->developerEmail)
                ->subject(\sprintf('[%s #%d] %s', $ticket->getKind()->label(), $ticket->getId(), $ticket->getSubject()))
                ->text(implode("\n\n", array_filter([
                    \sprintf('Von %s, %s', $ticket->getAuthor()?->getUsername() ?? 'gelöschter Spieler', $ticket->getCreatedAt()->setTimezone(new \DateTimeZone(\App\I18n\DisplayZone::DEFAULT))->format('d.m.Y H:i')),
                    $ticket->getBody(),
                    '' === $note ? null : 'Anmerkung von '.$admin->getUsername().":\n".$note,
                ]))));
        }
        $this->notifyAuthor($ticket, '');
        $this->em->flush();
    }

    /** Penalty for a reported player: vacation he can't end early (usually 1–3 days). */
    public function penalize(Ticket $ticket, User $admin, float $days, string $reason): \DateTimeImmutable
    {
        $player = $ticket->getReported();
        if (TicketKind::Report !== $ticket->getKind() || null === $player) {
            throw new GameException(t('Strafen gibt es nur bei gemeldeten Spielern.'));
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new GameException(t('Gib einen Grund für die Strafe an.'));
        }
        $until = $this->vacations->impose($player, $days, $reason);
        // Ticket comments are a record for the admins: kept in German.
        $ticket->comment($admin, \sprintf('Strafe verhängt: %s Tage Urlaub für %s. Grund: %s', str_replace('.', ',', (string) round($days, 2)), $player->getUsername(), $reason), true, $this->now());
        $ticket->setStatus(TicketStatus::Accepted, $this->now());
        $this->notifyAuthor($ticket, 'Der gemeldete Spieler wurde bestraft.');
        $this->em->flush();

        return $until;
    }

    /** @return list<User> */
    public function admins(): array
    {
        return array_values(array_filter($this->users->findAll(), static fn (User $u): bool => [] !== array_intersect(['ROLE_ADMIN', 'ROLE_MODERATOR'], $u->getRoles())));
    }

    private function notifyAuthor(Ticket $ticket, string $body): void
    {
        if (null === $author = $ticket->getAuthor()) {
            return;
        }
        if ($ticket->getKind()->isFromTeam()) {
            // Anonymous: the support team writes, nobody by name.
            $this->inbox->send($author, t('Nachricht vom Support-Team: {subject}', ['{subject}' => $ticket->getSubject()]), new Lines([
                '' === trim($body) ? t('Der Status ist jetzt „{label}“.', ['{label}' => t($ticket->getStatus()->label())]) : trim($body),
                t("\nDu findest den Verlauf unter Hilfe & Meldungen."),
            ]), ...$this->playerButton($ticket));

            return;
        }
        $this->inbox->send($author, t('{label} „{subject}“: {label2}', ['{label}' => t($ticket->getKind()->label()), '{subject}' => $ticket->getSubject(), '{label2}' => t($ticket->getStatus()->label())]), new Lines([t('Deine Meldung hat jetzt den Status „{label}“.', ['{label}' => t($ticket->getStatus()->label())]), '' === trim($body) ? null : "\n".trim($body), t("\nDu findest sie unter Hilfe & Meldungen.")]), ...$this->playerButton($ticket));
    }

    /**
     * The button under a message to the player: straight to the dialog, to answer there.
     *
     * @return array{0?: string, 1?: \Symfony\Contracts\Translation\TranslatableInterface}
     */
    private function playerButton(Ticket $ticket): array
    {
        return null === $this->urls || null === $ticket->getId() ? [] : [$this->urls->generate('app_support_show', ['id' => $ticket->getId()]), t('Antworten →')];
    }

    /** @return array{0?: string, 1?: \Symfony\Contracts\Translation\TranslatableInterface} the button under a message to the team */
    private function teamButton(Ticket $ticket): array
    {
        return null === $this->urls || null === $ticket->getId() ? [] : [$this->urls->generate('app_admin_ticket', ['id' => $ticket->getId()]), t('Meldung öffnen →')];
    }

    private function text(string $body): string
    {
        $body = trim($body);
        if ('' === $body) {
            throw new GameException(t('Die Nachricht ist leer.'));
        }

        return $body;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
