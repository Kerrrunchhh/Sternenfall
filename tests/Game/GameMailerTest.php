<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\GameMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

use function Symfony\Component\Translation\t;

/** Mails fill in their placeholders – no "{name}" left over, in German (no catalog) or English. */
final class GameMailerTest extends TestCase
{
    /** @var list<Email> */
    private array $sent = [];

    public function testConfirmationMailHasNoBracesLeft(): void
    {
        foreach (['de' => 'Hallo Jochen,', 'en' => 'Hello Jochen,'] as $locale => $greeting) {
            $this->sent = [];
            $this->mailer()->sendConfirmation($this->user($locale));

            $body = $this->sent[0]->getTextBody();
            self::assertStringStartsWith($greeting, $body, $locale);
            self::assertStringContainsString('Starfall: New Horizons', $body, $locale);
            self::assertStringContainsString("\n\nhttps://example.org/confirm?id=4&_hash=x\n\n", $body, $locale);
            self::assertDoesNotMatchRegularExpression('/[{}]/', $body, $locale);
        }
    }

    public function testNotificationFooterNamesTheGame(): void
    {
        foreach (['de', 'en'] as $locale) {
            $this->sent = [];
            $user = $this->user($locale);
            $user->verifyEmail(new \DateTimeImmutable('2026-11-11'));
            $user->setMailTopics(array_keys(GameMailer::TOPICS));
            $this->mailer()->notify($user, 'research', t('Forschung abgeschlossen'), t('Forschung abgeschlossen'));

            $body = $this->sent[0]->getTextBody();
            self::assertStringContainsString("\n\n– Starfall: New Horizons\n", $body, $locale);
            self::assertDoesNotMatchRegularExpression('/[{}]/', $body, $locale);
        }
    }

    private function user(string $locale): User
    {
        $user = new User('Jochen');
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, 4);
        $user->setEmail('jochen@example.org');
        $user->setLocale($locale);

        return $user;
    }

    private function mailer(): GameMailer
    {
        $mailer = new class($this->sent) implements MailerInterface {
            /** @param list<Email> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                \assert($message instanceof Email);
                $this->sent[] = $message;
            }
        };
        $signer = $this->createStub(UriSigner::class);
        $signer->method('sign')->willReturn('https://example.org/confirm?id=4&_hash=x');
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('https://example.org/confirm?id=4');

        $translator = new Translator('de');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__.'/../../translations/messages+intl-icu.en.yaml', 'en', 'messages+intl-icu');
        $translator->setFallbackLocales(['de']);

        return new GameMailer($mailer, $signer, $urls, new NullLogger(), 'noreply@example.org', 'Starfall: New Horizons', $translator);
    }
}
