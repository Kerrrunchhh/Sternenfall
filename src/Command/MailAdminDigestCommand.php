<?php

namespace App\Command;

use App\Game\AdminNotices;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Mails the admins what is new (sign-ups, booked extended accounts) – nothing if nothing happened.
 * Run it every 10 minutes by cron:  *\/10 * * * * cd /var/www/earthlost && php bin/console app:mail-admin-digest
 */
#[AsCommand(name: 'app:mail-admin-digest', description: 'Mails the admins new sign-ups and booked extended accounts')]
final readonly class MailAdminDigestCommand
{
    public function __construct(private AdminNotices $notices)
    {
    }

    public function __invoke(OutputInterface $output): int
    {
        $output->writeln(\sprintf('%d Ereignis(se) gemeldet.', $this->notices->sendDigest()));

        return Command::SUCCESS;
    }
}
