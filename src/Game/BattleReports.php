<?php

namespace App\Game;

use App\Entity\BattleReport;
use App\Repository\BattleReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Stores combat reports under a random hash so they can be shared by link for 30 days.
 */
final readonly class BattleReports
{
    /** Reports in a link: /kampfbericht/<24 hex digits>. */
    public const string LINK_PATTERN = '~(?:https?://[^\s/]+)?/kampfbericht/([0-9a-f]{24})\b~';

    public function __construct(
        private EntityManagerInterface $em,
        private BattleReportRepository $reports,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    /** Saves the report (persisted, flushed by the caller) and returns its link path. */
    public function publish(string $subject, string $body): string
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        // Old reports go when new ones come.
        $this->reports->deleteCreatedBefore($now->modify('-'.BattleReport::DAYS_AVAILABLE.' days'));

        $report = new BattleReport(bin2hex(random_bytes(12)), $subject, $body, $now);
        $this->em->persist($report);

        return $this->urls->generate('app_battle_report', ['hash' => $report->getHash()]);
    }

    public function find(string $hash): ?BattleReport
    {
        $report = $this->reports->findOneBy(['hash' => $hash]);

        return null === $report || $report->isExpired(\DateTimeImmutable::createFromInterface($this->clock->now())) ? null : $report;
    }
}
