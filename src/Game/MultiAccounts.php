<?php

namespace App\Game;

use App\Entity\LoginRecord;
use App\Entity\ResourceGift;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Multi account detection: logins with IP, browser and (with consent) a device fingerprint
 * are kept for 30 days; accounts sharing an IP or a device are listed for the admins
 * together with the resources they sent each other. Nothing is blocked automatically –
 * families and flatmates share an IP too.
 */
final readonly class MultiAccounts
{
    public const int KEEP_DAYS = 30;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function recordLogin(User $user, ?string $ip, ?string $userAgent, ?string $fingerprint): void
    {
        if (null === $ip || $user->isSystem()) {
            return;
        }
        $fingerprint = null !== $fingerprint && 1 === preg_match('/^[0-9a-f]{64}$/', $fingerprint) ? $fingerprint : null;
        $now = $this->now();
        $this->em->persist(new LoginRecord($user, $ip, (string) $userAgent, $fingerprint, $now));
        $this->em->flush();
        $this->prune($now);
    }

    /** Deletes login records older than 30 days. */
    public function prune(?\DateTimeImmutable $now = null): int
    {
        return $this->em->createQuery('DELETE FROM '.LoginRecord::class.' r WHERE r.createdAt < :before')
            ->setParameter('before', ($now ?? $this->now())->modify('-'.self::KEEP_DAYS.' days'))
            ->execute();
    }

    /**
     * Pairs of accounts that logged in from the same IP or the same device in the last 30 days.
     *
     * @return list<array{a: User, b: User, ips: int, devices: int, last: \DateTimeImmutable, gifts: float}>
     */
    public function suspiciousPairs(): array
    {
        $connection = $this->em->getConnection();
        $rows = $connection->fetchAllAssociative(
            'SELECT a.user_id AS a, b.user_id AS b,
                    COUNT(DISTINCT CASE WHEN a.ip = b.ip THEN a.ip END) AS ips,
                    COUNT(DISTINCT CASE WHEN a.fingerprint IS NOT NULL AND a.fingerprint = b.fingerprint THEN a.fingerprint END) AS devices,
                    MAX(GREATEST(a.created_at, b.created_at)) AS last
             FROM login_record a
             JOIN login_record b ON a.user_id < b.user_id AND (a.ip = b.ip OR (a.fingerprint IS NOT NULL AND a.fingerprint = b.fingerprint))
             GROUP BY a.user_id, b.user_id
             ORDER BY devices DESC, ips DESC, last DESC
             LIMIT 200',
        );
        $since = $this->now()->modify('-'.self::KEEP_DAYS.' days');
        $pairs = [];
        foreach ($rows as $row) {
            $a = $this->users->find((int) $row['a']);
            $b = $this->users->find((int) $row['b']);
            if (null === $a || null === $b) {
                continue;
            }
            $gifts = (float) $this->em->createQuery('SELECT COALESCE(SUM(g.amount), 0) FROM '.ResourceGift::class.' g WHERE ((g.sender = :a AND g.receiver = :b) OR (g.sender = :b AND g.receiver = :a)) AND g.createdAt >= :since')
                ->setParameters(['a' => $a, 'b' => $b, 'since' => $since])->getSingleScalarResult();
            $pairs[] = ['a' => $a, 'b' => $b, 'ips' => (int) $row['ips'], 'devices' => (int) $row['devices'], 'last' => new \DateTimeImmutable((string) $row['last'], new \DateTimeZone('UTC')), 'gifts' => $gifts];
        }

        return $pairs;
    }

    /**
     * Biggest one-way resource streams of the last 7 days (pushing candidates).
     *
     * @return list<array{sender: User, receiver: User, amount: float, count: int}>
     */
    public function biggestGifts(int $limit = 30): array
    {
        $rows = $this->em->createQuery('SELECT IDENTITY(g.sender) AS s, IDENTITY(g.receiver) AS r, SUM(g.amount) AS total, COUNT(g.id) AS n FROM '.ResourceGift::class.' g WHERE g.createdAt >= :since GROUP BY g.sender, g.receiver ORDER BY total DESC')
            ->setParameter('since', $this->now()->modify('-7 days'))->setMaxResults($limit)->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $s = $this->users->find((int) $row['s']);
            $r = $this->users->find((int) $row['r']);
            if (null !== $s && null !== $r) {
                $out[] = ['sender' => $s, 'receiver' => $r, 'amount' => (float) $row['total'], 'count' => (int) $row['n']];
            }
        }

        return $out;
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
