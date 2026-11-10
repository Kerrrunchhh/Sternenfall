<?php

namespace App\Command;

use App\Entity\User;
use App\Game\GameMailer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

use function Symfony\Component\Translation\t;

/**
 * Announces finished research by e-mail (for players who want it). Run it every
 * minute by cron:  * * * * * cd /var/www/earthlost && php bin/console app:mail-research
 */
#[AsCommand(name: 'app:mail-research', description: 'Sends e-mails about finished research')]
final class MailResearchCommand
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GameMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $users = $this->em->createQuery('SELECT u FROM '.User::class.' u WHERE u.researchFinishesAt IS NOT NULL AND u.researchFinishesAt <= :now AND u.researchMailSent = false AND u.emailVerifiedAt IS NOT NULL')
            ->setParameter('now', $now)
            ->getResult();
        foreach ($users as $user) {
            if ($user->isOnVacation($now)) {
                continue;
            }
            $this->mailer->notify($user, 'research', t('Forschung abgeschlossen'), t('{value} Stufe {researchTargetLevel} ist fertig erforscht. Dein Forschungslabor wartet auf den nächsten Auftrag.', ['{value}' => $user->getResearchType()?->label() ?? 'Deine Forschung', '{researchTargetLevel}' => $user->getResearchTargetLevel()]));
            $user->markResearchMailSent();
        }
        $this->em->flush();
        $output->writeln(\sprintf('%d Forschungsmail(s) geprüft.', \count($users)));

        return Command::SUCCESS;
    }
}
