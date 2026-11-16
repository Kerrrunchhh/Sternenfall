<?php

namespace App\Command;

use App\Entity\User;
use App\Game\GameMailer;
use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the registration confirmation link again – to the named players, or with --all to
 * everyone who never confirmed the address and never logged in (deactivated accounts and
 * pending deletions left out). Try --dry-run first: it only lists who would get a mail.
 */
#[AsCommand(name: 'app:resend-confirmation', description: 'Sends the e-mail confirmation link again')]
final class ResendConfirmationCommand
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly GameMailer $mailer,
    ) {
    }

    /** @param list<string> $usernames */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Players to send it to (else use --all)')] array $usernames = [],
        #[Option(description: 'Everyone with an unconfirmed address who never logged in')] bool $all = false,
        #[Option(name: 'dry-run', description: 'Only list who would get a mail')] bool $dryRun = false,
    ): int {
        if ([] === $usernames && !$all) {
            $io->error('Nenne Spielernamen oder nutze --all.');

            return Command::INVALID;
        }
        $players = $all
            ? array_values(array_filter($this->users->findAll(), static fn (User $u): bool => $u->needsEmailConfirmation() && null === $u->getLastSeenAt()))
            : array_values(array_filter(array_map(fn (string $name): ?User => $this->users->findOneBy(['username' => $name]), $usernames)));

        $sent = 0;
        foreach ($players as $player) {
            if (!$player->needsEmailConfirmation() || $player->isDeactivated() || null !== $player->getDeleteAt()) {
                $io->writeln(\sprintf('übersprungen: %s (bestätigt, deaktiviert oder Löschung läuft)', $player->getUsername()));
                continue;
            }
            $io->writeln(\sprintf('%s %s, angemeldet %s', $dryRun ? 'würde senden an' : 'gesendet an', $player->getUsername(), $player->getCreatedAt()->format('d.m.Y H:i')));
            if (!$dryRun) {
                $this->mailer->sendConfirmation($player);
            }
            ++$sent;
        }
        $io->success(\sprintf($dryRun ? '%d Bestätigungslink(s) würden verschickt.' : '%d Bestätigungslink(s) verschickt.', $sent));

        return Command::SUCCESS;
    }
}
