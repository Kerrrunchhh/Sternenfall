<?php

namespace App\Game;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * E-mails of the game: confirming the address and notifications the player chose
 * (attacks on his planets, finished research, new letters, battle reports).
 * Without MAILER_DSN the mails are dropped (and the links logged).
 */
final readonly class GameMailer
{
    /** @var array<string, string> topic => label */
    public const array TOPICS = [
        'attack' => 'Angriff auf einen meiner Planeten (sobald mein Radar ihn sieht)',
        'research' => 'Forschung abgeschlossen',
        'mail' => 'Neue Nachricht in der Post',
        'battle' => 'Kampfbericht (ich war an einer Schlacht beteiligt)',
    ];

    public function __construct(
        private MailerInterface $mailer,
        private UriSigner $signer,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from = 'noreply@example.org',
        #[Autowire(env: 'GAME_NAME')]
        private string $gameName = 'Sternenfall',
    ) {
    }

    /** Sends the confirmation link (valid for 2 days); returns it (shown in development). */
    public function sendConfirmation(User $user): string
    {
        $url = $this->signer->sign($this->urls->generate('app_verify_email', ['id' => $user->getId(), 'email' => $user->getEmail()], UrlGeneratorInterface::ABSOLUTE_URL), new \DateInterval('P2D'));
        $this->send($user->getEmail(), 'Bitte bestätige deine E-Mail-Adresse', \sprintf("Hallo %s,\n\nwillkommen bei %s! Bitte bestätige deine E-Mail-Adresse mit diesem Link (2 Tage gültig):\n\n%s\n\nWenn du dich nicht angemeldet hast, ignoriere diese Mail einfach.", $user->getUsername(), $this->gameName, $url));
        $this->logger->info('E-mail confirmation link for {user}: {url}', ['user' => $user->getUsername(), 'url' => $url]);

        return $url;
    }

    /** A notification the player chose in his account settings. */
    public function notify(User $user, string $topic, string $subject, string $text): void
    {
        if (!$user->wantsMail($topic)) {
            return;
        }
        $this->send($user->getEmail(), $subject, $text."\n\n– ".$this->gameName."\nWelche Mails du bekommst, stellst du im Spiel unter Konto ein.");
    }

    private function send(?string $to, string $subject, string $text): void
    {
        if (null === $to) {
            return;
        }
        try {
            $this->mailer->send((new Email())->from($this->from)->to($to)->subject('['.$this->gameName.'] '.$subject)->text($text));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not send e-mail to {to}: {error}', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
