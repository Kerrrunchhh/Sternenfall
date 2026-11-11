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
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

/**
 * E-mails of the game: confirming the address and notifications the player chose
 * (attacks on his planets, finished research, new letters, battle reports).
 * Without MAILER_DSN the mails are dropped (and the links logged).
 */
final readonly class GameMailer
{
    /** @var array<string, string> topic => label */
    public const array TOPICS = [
        'attack' => 'Angriff auf einen meiner Planeten',
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
        private string $gameName = 'Starfall: New Horizons',
        private TranslatorInterface $translator = new IdentityTranslator(),
    ) {
    }

    /** Sends the confirmation link (valid for 2 days); returns it (shown in development). */
    public function sendConfirmation(User $user): string
    {
        $url = $this->signer->sign($this->urls->generate('app_verify_email', ['id' => $user->getId(), 'email' => $user->getEmail()], UrlGeneratorInterface::ABSOLUTE_URL), new \DateInterval('P2D'));
        $this->send($user, t('Bitte bestätige deine E-Mail-Adresse'), t("Hallo {name},\n\nwillkommen bei {game}! Bitte bestätige deine E-Mail-Adresse mit diesem Link (2 Tage gültig):\n\n{url}\n\nWenn du dich nicht angemeldet hast, ignoriere diese Mail einfach.", ['name' => $user->getUsername(), 'game' => $this->gameName, 'url' => $url]));
        $this->logger->info('E-mail confirmation link for {user}: {url}', ['user' => $user->getUsername(), 'url' => $url]);

        return $url;
    }

    /** A mail the player gets regardless of his notification settings (e.g. a contract confirmation). */
    public function sendAlways(User $user, TranslatableInterface $subject, TranslatableInterface $text): void
    {
        $this->send($user, $subject, $text, t("– {game}", ['{game}' => $this->gameName]));
    }

    /** A notification the player chose in his account settings. */
    public function notify(User $user, string $topic, TranslatableInterface $subject, TranslatableInterface $text): void
    {
        if (!$user->wantsMail($topic)) {
            return;
        }
        $this->send($user, $subject, $text, t("– {game}\nWelche Mails du bekommst, stellst du im Spiel unter Konto ein.", ['game' => $this->gameName]));
    }

    /** Sends a mail in the language the player chose. */
    private function send(User $user, TranslatableInterface $subject, TranslatableInterface $text, ?TranslatableInterface $footer = null): void
    {
        $to = $user->getEmail();
        if (null === $to) {
            return;
        }
        $locale = $user->getLocale();
        $body = $text->trans($this->translator, $locale).(null === $footer ? '' : "\n\n".$footer->trans($this->translator, $locale));
        try {
            $this->mailer->send((new Email())->from($this->from)->to($to)->subject('['.$this->gameName.'] '.$subject->trans($this->translator, $locale))->text($body));
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Could not send e-mail to {to}: {error}', ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
