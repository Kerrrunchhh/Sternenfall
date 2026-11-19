<?php

namespace App\Game;

use App\Entity\AdminNotice;
use App\Entity\User;
use App\I18n\Raw;
use App\Repository\AdminNoticeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/**
 * Tells the admins about sign-ups and booked extended accounts: each event is noted at once,
 * the command app:mail-admin-digest mails what is new every 10 minutes (one mail per admin).
 * Only for the admins, so the texts are German and not translated.
 */
final readonly class AdminNotices
{
    public function __construct(
        private EntityManagerInterface $em,
        private AdminNoticeRepository $notices,
        private GameMailer $mailer,
        private ClockInterface $clock,
        private LockFactory $locks,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $baseUrl = '',
    ) {
    }

    public function record(string $kind, User $user): void
    {
        $this->em->persist(new AdminNotice($kind, $user, \DateTimeImmutable::createFromInterface($this->clock->now())));
        $this->em->flush();
    }

    /** Mails everything new to every admin; returns how many events went out. */
    public function sendDigest(): int
    {
        $lock = $this->locks->createLock('admin_digest');
        if (!$lock->acquire()) {
            return 0;
        }
        try {
            $notices = $this->notices->findUnsent();
            if ([] === $notices) {
                return 0;
            }
            foreach ($this->notices->findAdmins() as $admin) {
                [$subject, $text] = self::compose($notices, new \DateTimeZone($admin->getTimezone()), $this->baseUrl);
                $this->mailer->sendAlways($admin, new Raw($subject), new Raw($text));
            }
            $now = \DateTimeImmutable::createFromInterface($this->clock->now());
            foreach ($notices as $notice) {
                $notice->markSent($now);
            }
            $this->em->flush();

            return \count($notices);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<AdminNotice> $notices
     *
     * @return array{string, string} subject and text
     */
    public static function compose(array $notices, \DateTimeZone $zone, string $baseUrl = ''): array
    {
        $signups = array_values(array_filter($notices, static fn (AdminNotice $n): bool => AdminNotice::SIGNUP === $n->getKind()));
        $bookings = array_values(array_filter($notices, static fn (AdminNotice $n): bool => AdminNotice::EXTENDED === $n->getKind()));
        $at = static fn (AdminNotice $n): string => $n->getCreatedAt()->setTimezone($zone)->format('d.m. H:i');

        $parts = [];
        $lines = [];
        if ([] !== $signups) {
            $parts[] = 1 === \count($signups) ? '1 Anmeldung' : \count($signups).' Anmeldungen';
            $lines[] = 'Neue Anmeldungen:';
            foreach ($signups as $n) {
                $user = $n->getUser();
                $lines[] = \sprintf('– %s %s%s', $at($n), $n->getUsername(), null === $user ? ' (Konto schon gelöscht)'
                    : \sprintf(' (%s, E-Mail %s)', $user->getLifeForm()->label(), $user->isEmailVerified() ? 'bestätigt' : 'noch nicht bestätigt'));
            }
            $lines[] = '';
        }
        if ([] !== $bookings) {
            $parts[] = 1 === \count($bookings) ? '1 erweiterter Account gebucht' : \count($bookings).' erweiterte Accounts gebucht';
            $lines[] = 'Erweiterter Account gebucht:';
            foreach ($bookings as $n) {
                $lines[] = \sprintf('– %s %s', $at($n), $n->getUsername());
            }
            $lines[] = '';
        }
        if ('' !== $baseUrl) {
            $lines[] = 'Neueste Anmeldungen: '.rtrim($baseUrl, '/').'/admin/anmeldungen';
        }

        return ['Admin: '.implode(', ', $parts), rtrim(implode("\n", $lines))];
    }
}
