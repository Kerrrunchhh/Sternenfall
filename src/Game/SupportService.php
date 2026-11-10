<?php

namespace App\Game;

use App\Entity\Ticket;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

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
            throw new GameException('Bitte gib einen Betreff und eine Beschreibung an.');
        }
        $reported = null;
        if (TicketKind::Report === $kind) {
            $reported = $this->users->findOneBy(['username' => trim($reportedName)]);
            if (null === $reported) {
                throw new GameException(\sprintf('Einen Spieler „%s“ gibt es nicht.', trim($reportedName)));
            }
            if ($reported->isSameAs($author)) {
                throw new GameException('Dich selbst kannst du nicht melden.');
            }
        }
        $ticket = new Ticket($kind, $author, $reported, $subject, $body, $this->now());
        $this->em->persist($ticket);
        foreach ($this->admins() as $admin) {
            $this->inbox->send($admin, \sprintf('Neue Meldung: %s %s', $kind->icon(), $kind->label()), \sprintf('%s hat eine Meldung geschickt: „%s“. Bearbeiten im Adminbereich unter Meldungen.', $author->getUsername(), $ticket->getSubject()));
        }
        $this->em->flush();

        return $ticket;
    }

    /** The author answers a question of the admins (or adds something). */
    public function reply(Ticket $ticket, User $author, string $body): void
    {
        if (!$ticket->isByAuthor($author)) {
            throw new GameException('Das ist nicht deine Meldung.');
        }
        $body = $this->text($body);
        $ticket->comment($author, $body, false, $this->now());
        if (TicketStatus::Question === $ticket->getStatus()) {
            $ticket->setStatus(TicketStatus::Open, $this->now());
        }
        foreach ($this->admins() as $admin) {
            $this->inbox->send($admin, 'Antwort auf Meldung: '.$ticket->getSubject(), \sprintf('%s hat geantwortet. Bearbeiten im Adminbereich unter Meldungen.', $author->getUsername()));
        }
        $this->em->flush();
    }

    /** An admin writes (a question if $status is Question) and/or changes the status. */
    public function answer(Ticket $ticket, User $admin, string $body, TicketStatus $status): void
    {
        $body = trim($body);
        if ('' === $body && $status === $ticket->getStatus()) {
            throw new GameException('Schreib eine Nachricht oder ändere den Status.');
        }
        if (TicketStatus::Question === $status && '' === $body) {
            throw new GameException('Bei einer Rückfrage gehört die Frage dazu.');
        }
        if ('' !== $body) {
            $ticket->comment($admin, $body, true, $this->now());
        }
        $ticket->setStatus($status, $this->now());
        $this->notifyAuthor($ticket, $body);
        $this->em->flush();
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
                    \sprintf('Von %s, %s', $ticket->getAuthor()?->getUsername() ?? 'gelöschter Spieler', $ticket->getCreatedAt()->format('d.m.Y H:i')),
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
            throw new GameException('Strafen gibt es nur bei gemeldeten Spielern.');
        }
        $reason = trim($reason);
        if ('' === $reason) {
            throw new GameException('Gib einen Grund für die Strafe an.');
        }
        $until = $this->vacations->impose($player, $days, $reason);
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
        $this->inbox->send($author, \sprintf('%s „%s“: %s', $ticket->getKind()->label(), $ticket->getSubject(), $ticket->getStatus()->label()), trim(\sprintf("Deine Meldung hat jetzt den Status „%s“.\n\n%s\n\nDu findest sie unter Hilfe & Meldungen.", $ticket->getStatus()->label(), $body)));
    }

    private function text(string $body): string
    {
        $body = trim($body);
        if ('' === $body) {
            throw new GameException('Die Nachricht ist leer.');
        }

        return $body;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
