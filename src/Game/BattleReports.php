<?php

namespace App\Game;

use App\Entity\BattleReport;
use App\Repository\BattleReportRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use App\I18n\Locales;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private TranslatorInterface $translator,
        private Locales $locales,
    ) {
    }

    /** Saves the report (persisted, flushed by the caller) and returns its link path. */
    /** @param array<int, array{name: string, ships: array<string, int>}> $losses what each participant lost (for refunds) */
    public function publish(TranslatableInterface $subject, TranslatableInterface $body, array $losses = []): string
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        // Old reports go when new ones come.
        $this->reports->deleteCreatedBefore($now->modify('-'.BattleReport::DAYS_AVAILABLE.' days'));

        // Written once in every language, so each reader gets his own.
        $translations = [];
        foreach ($this->locales->all() as $locale) {
            if ($locale !== $this->locales->default()) {
                $translations[$locale] = ['subject' => $subject->trans($this->translator, $locale), 'body' => $body->trans($this->translator, $locale)];
            }
        }
        $default = $this->locales->default();
        $report = new BattleReport(bin2hex(random_bytes(12)), $subject->trans($this->translator, $default), $body->trans($this->translator, $default), $now, $translations);
        $report->setLosses($losses);
        $this->em->persist($report);

        return $this->urls->generate('app_battle_report', ['hash' => $report->getHash()]);
    }

    public function find(string $hash): ?BattleReport
    {
        $report = $this->reports->findOneBy(['hash' => $hash]);

        return null === $report || $report->isExpired(\DateTimeImmutable::createFromInterface($this->clock->now())) ? null : $report;
    }
}
