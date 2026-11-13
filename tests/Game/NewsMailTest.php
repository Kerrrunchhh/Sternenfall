<?php

namespace App\Tests\Game;

use App\Entity\News;
use App\Entity\User;
use App\Game\GameMailer;
use App\Game\NewsService;
use App\Repository\NewsRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Psr\Log\NullLogger;
use Symfony\Component\Translation\IdentityTranslator;

/** Mail notifications: all on until the player chooses, and news are mailed once. */
final class NewsMailTest extends TestCase
{
    public function testEveryNotificationIsOnUntilThePlayerChooses(): void
    {
        $player = new User('neu');
        self::assertSame(array_keys(GameMailer::TOPICS), $player->getMailTopics());
        self::assertContains('news', $player->getMailTopics());

        $player->setMailTopics(['attack']);
        self::assertSame(['attack'], $player->getMailTopics(), 'what he chose counts');
    }

    public function testNewNewsAreMailedOnceInTheLanguageOfEachPlayer(): void
    {
        $now = new \DateTimeImmutable('2026-11-13 10:00:00');
        $news = new News($now);
        $news->setTitle('Neue Gilde');
        $news->setBody('Die Handelsgilde ist da.');
        $news->setTitleEn('New guild');
        $news->setBodyEn('The trade guild is here.');

        $german = $this->verified('deutsch', 'de@example.org', 'de');
        $english = $this->verified('english', 'en@example.org', 'en');
        $quiet = $this->verified('ruhig', 'q@example.org', 'de');
        $quiet->setMailTopics(['attack']);
        $unverified = new User('ohne');

        $repository = $this->createStub(NewsRepository::class);
        $repository->method('findBy')->willReturnCallback(static fn (array $by): array => null === $news->getMailedAt() ? [$news] : []);
        $users = $this->createStub(UserRepository::class);
        $users->method('findBy')->willReturn([$german, $english, $quiet, $unverified]);

        $sent = [];
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (Email $email) use (&$sent): void {
            $sent[] = [$email->getSubject(), array_map(static fn ($a): string => $a->getAddress(), $email->getBcc()), $email->getTo()[0]->getAddress()];
        });
        $gameMailer = new GameMailer($mailer, new UriSigner('secret'), $this->createStub(UrlGeneratorInterface::class), new NullLogger(), 'noreply@example.org', 'Starfall', new IdentityTranslator());
        $service = new NewsService($this->createStub(EntityManagerInterface::class), $repository, new MockClock($now), $users, $gameMailer);

        self::assertSame(1, $service->mailPending());
        self::assertSame([
            ['[Starfall] Neue Gilde', ['de@example.org'], 'noreply@example.org'],
            ['[Starfall] New guild', ['en@example.org'], 'noreply@example.org'],
        ], $sent, 'one mail per language, the players in blind copy – only who wants it and has a verified address');
        self::assertEquals($now, $news->getMailedAt());

        $sent = [];
        self::assertSame(0, $service->mailPending(), 'never twice');
        self::assertSame([], $sent);
    }

    public function testManyPlayersAreSplitIntoBatchesOfBlindCopies(): void
    {
        $players = [];
        foreach (range(1, GameMailer::BCC_BATCH + 5) as $i) {
            $players[] = $this->verified('p'.$i, 'p'.$i.'@example.org', 'de');
        }
        $batches = [];
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (Email $email) use (&$batches): void {
            $batches[] = \count($email->getBcc());
        });
        $gameMailer = new GameMailer($mailer, new UriSigner('secret'), $this->createStub(UrlGeneratorInterface::class), new NullLogger());

        $count = $gameMailer->broadcast($players, 'news', static fn (): \App\I18n\Raw => new \App\I18n\Raw('Titel'), static fn (): \App\I18n\Raw => new \App\I18n\Raw('Text'));

        self::assertSame(GameMailer::BCC_BATCH + 5, $count);
        self::assertSame([GameMailer::BCC_BATCH, 5], $batches);
    }

    private function verified(string $name, string $email, string $locale): User
    {
        $user = new User($name);
        $user->setEmail($email);
        $user->verifyEmail(new \DateTimeImmutable('2026-11-01'));
        $user->setLocale($locale);

        return $user;
    }
}
