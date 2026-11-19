<?php

namespace App\Tests\Game;

use App\Entity\AdminNotice;
use App\Entity\User;
use App\Game\AdminNotices;
use App\Game\GameMailer;
use App\Payment\PayPalSubscriptions;
use App\Repository\AdminNoticeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** The admins hear about sign-ups and booked extended accounts – in one mail every 10 minutes. */
final class AdminNoticesTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-11-19 10:00:00 UTC');
    }

    public function testTheDigestListsSignUpsAndBookings(): void
    {
        $confirmed = new User('Neuling', $this->now);
        $confirmed->setEmail('neuling@example.org');
        $confirmed->verifyEmail($this->now);
        $notices = [
            new AdminNotice(AdminNotice::SIGNUP, $confirmed, $this->now),
            new AdminNotice(AdminNotice::SIGNUP, new User('Zaghaft', $this->now), $this->now->modify('+3 minutes')),
            new AdminNotice(AdminNotice::EXTENDED, new User('Gönner', $this->now), $this->now->modify('+5 minutes')),
        ];

        [$subject, $text] = AdminNotices::compose($notices, new \DateTimeZone('Europe/Berlin'), 'https://starfallnewhorizons.com');

        self::assertSame('Admin: 2 Anmeldungen, 1 erweiterter Account gebucht', $subject);
        self::assertStringContainsString('– 19.11. 11:00 Neuling (Siedler, E-Mail bestätigt)', $text);
        self::assertStringContainsString('– 19.11. 11:03 Zaghaft (Siedler, E-Mail noch nicht bestätigt)', $text);
        self::assertStringContainsString("Erweiterter Account gebucht:\n– 19.11. 11:05 Gönner", $text);
        self::assertStringContainsString('https://starfallnewhorizons.com/admin/anmeldungen', $text);
    }

    public function testPayPalProblemsAreListedWithWhatHappened(): void
    {
        $notice = new AdminNotice(AdminNotice::PAYPAL, new User('Kaeufer', $this->now), $this->now, 'PayPal meldet einen Fehler – „Window closed“ · Browser: Firefox');

        [$subject, $text] = AdminNotices::compose([$notice], new \DateTimeZone('Europe/Berlin'));

        self::assertSame('Admin: 1 PayPal-Problem', $subject);
        self::assertStringContainsString("PayPal-Probleme:\n– 19.11. 11:00 Kaeufer: PayPal meldet einen Fehler – „Window closed“ · Browser: Firefox", $text);
    }

    public function testAPlayerCanReportAtMostFivePayPalProblemsAnHour(): void
    {
        $player = new User('Kaeufer', $this->now);
        foreach ([4 => 1, 5 => 0] as $already => $persisted) {
            $repository = $this->createStub(AdminNoticeRepository::class);
            $repository->method('countSince')->willReturn($already);
            $em = $this->createMock(EntityManagerInterface::class);
            $em->expects(self::exactly($persisted))->method('persist');
            $notices = new AdminNotices($em, $repository, $this->mailer($this->createStub(MailerInterface::class)), new MockClock($this->now), new LockFactory(new InMemoryStore()));

            $notices->record(AdminNotice::PAYPAL, $player, 'PayPal-Fenster geschlossen');
        }
    }

    public function testNewEventsGoOutOnceToEveryAdminAndNothingIsSentWithoutNews(): void
    {
        $admin = new User('Chefin', $this->now);
        $admin->setEmail('chefin@example.org');
        $notice = new AdminNotice(AdminNotice::SIGNUP, new User('Neuling', $this->now), $this->now);
        $sent = [];
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(static function (Email $email) use (&$sent): void {
            $sent[] = $email;
        });

        self::assertSame(1, $this->notices([$notice], [$admin], $mailer)->sendDigest());
        self::assertCount(1, $sent);
        self::assertSame('chefin@example.org', $sent[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('Admin: 1 Anmeldung', (string) $sent[0]->getSubject());
        self::assertEquals($this->now, $notice->getSentAt(), 'marked as sent');

        self::assertSame(0, $this->notices([], [$admin], $mailer)->sendDigest());
        self::assertCount(1, $sent, 'no news, no mail');
    }

    public function testABookedExtendedAccountIsNotedOnceNotOnEveryRenewal(): void
    {
        $player = new User('Gönner', $this->now);
        (new \ReflectionProperty(User::class, 'id'))->setValue($player, 7);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::callback(static fn (AdminNotice $n): bool => AdminNotice::EXTENDED === $n->getKind() && 'Gönner' === $n->getUsername()));
        $notices = new AdminNotices($em, $this->createStub(AdminNoticeRepository::class), $this->mailer($this->createStub(MailerInterface::class)), new MockClock($this->now), new LockFactory(new InMemoryStore()));
        $subscription = new MockResponse(json_encode(['id' => 'I-ABC123456', 'status' => 'ACTIVE', 'plan_id' => 'P-PLAN', 'custom_id' => '7', 'billing_info' => ['next_billing_time' => '2026-12-19T10:00:00Z']]));
        $paypal = new PayPalSubscriptions(new MockHttpClient([new MockResponse(json_encode(['access_token' => 'tok'])), $subscription, clone $subscription]), $this->createStub(EntityManagerInterface::class), $this->createStub(UserRepository::class), new MockClock($this->now), new NullLogger(), 'https://api-m.sandbox.paypal.com', 'client', 'secret', 'P-PLAN', 'WH-1', $notices);

        $paypal->activate($player, 'I-ABC123456');
        $paypal->activate($player, 'I-ABC123456'); // the same subscription again: no second note
    }

    /**
     * @param list<AdminNotice> $unsent
     * @param list<User>        $admins
     */
    private function notices(array $unsent, array $admins, MailerInterface $mailer): AdminNotices
    {
        $repository = $this->createStub(AdminNoticeRepository::class);
        $repository->method('findUnsent')->willReturn($unsent);
        $repository->method('findAdmins')->willReturn($admins);

        return new AdminNotices($this->createStub(EntityManagerInterface::class), $repository, $this->mailer($mailer), new MockClock($this->now), new LockFactory(new InMemoryStore()), 'https://starfallnewhorizons.com');
    }

    private function mailer(MailerInterface $mailer): GameMailer
    {
        return new GameMailer($mailer, new UriSigner('secret'), $this->createStub(UrlGeneratorInterface::class), new NullLogger());
    }
}
