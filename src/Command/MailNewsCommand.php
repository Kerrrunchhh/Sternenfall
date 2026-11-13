<?php

namespace App\Command;

use App\Game\NewsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Sends new news by e-mail to the players who want it. Run it every minute by cron:
 *   * * * * * cd /var/www/earthlost && php bin/console app:mail-news
 */
#[AsCommand(name: 'app:mail-news', description: 'Sends e-mails about new news')]
final class MailNewsCommand
{
    public function __construct(private readonly NewsService $news)
    {
    }

    public function __invoke(OutputInterface $output): int
    {
        $output->writeln(\sprintf('%d Neuigkeit(en) verschickt.', $this->news->mailPending()));

        return Command::SUCCESS;
    }
}
