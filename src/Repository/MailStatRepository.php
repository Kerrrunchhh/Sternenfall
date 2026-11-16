<?php

namespace App\Repository;

use App\Entity\MailStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<MailStat> */
class MailStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailStat::class);
    }

    /** Adds to the hour's counters in one statement, so mails sent at the same time don't overwrite each other. */
    public function add(\DateTimeImmutable $hour, int $mails, int $recipients, int $failed): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO mail_stat (hour, mails, recipients, failed) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE mails = mails + VALUES(mails), recipients = recipients + VALUES(recipients), failed = failed + VALUES(failed)',
            [$hour->format('Y-m-d H:00:00'), $mails, $recipients, $failed],
        );
    }

    /** @return array{mails: int, recipients: int, failed: int} totals from $since on */
    public function totalsSince(\DateTimeImmutable $since): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT COALESCE(SUM(mails), 0) mails, COALESCE(SUM(recipients), 0) recipients, COALESCE(SUM(failed), 0) failed FROM mail_stat WHERE hour >= ?',
            [$since->format('Y-m-d H:00:00')],
        ) ?: [];

        return ['mails' => (int) ($row['mails'] ?? 0), 'recipients' => (int) ($row['recipients'] ?? 0), 'failed' => (int) ($row['failed'] ?? 0)];
    }

    /** @return list<array{day: string, mails: int, recipients: int, failed: int}> per UTC day, newest first */
    public function perDay(int $days): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT DATE(hour) day, SUM(mails) mails, SUM(recipients) recipients, SUM(failed) failed FROM mail_stat WHERE hour >= ? GROUP BY DATE(hour) ORDER BY day DESC',
            [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.($days - 1).' days')->format('Y-m-d 00:00:00')],
        );

        return array_map(static fn (array $r): array => ['day' => (string) $r['day'], 'mails' => (int) $r['mails'], 'recipients' => (int) $r['recipients'], 'failed' => (int) $r['failed']], $rows);
    }
}
