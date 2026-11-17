<?php

namespace App\Command;

use App\Game\FleetService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off since free slots allow no stationing any more: sends every fleet still waiting at a
 * free slot home, free of charge, and tells the owners. Try --dry-run first.
 */
#[AsCommand(name: 'app:fleets-from-free-slots', description: 'Sends fleets waiting at free slots home free of charge')]
final class FleetsFromFreeSlotsCommand
{
    public function __construct(
        private readonly FleetService $fleets,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(name: 'dry-run', description: 'Only list the fleets')] bool $dryRun = false,
    ): int {
        $count = 0;
        foreach ($this->fleets->fleetsAtFreeSlots() as $fleet) {
            $where = \sprintf('[%d:%d:%d]', $fleet->getGalaxy(), $fleet->getSystem(), $fleet->getPosition());
            if ($dryRun) {
                $io->writeln(\sprintf('würde heimschicken: %s von %s auf %s', $fleet->getName() ?? '(ohne Namen)', $fleet->getOwner()->getUsername(), $where));
                ++$count;
                continue;
            }
            $home = $this->fleets->sendHomeFree($fleet);
            $io->writeln(null === $home
                ? \sprintf('kein Heimatplanet: %s von %s auf %s bleibt stehen', $fleet->getName() ?? '(ohne Namen)', $fleet->getOwner()->getUsername(), $where)
                : \sprintf('heimgeschickt: %s von %s, %s → %s [%s]', $fleet->getName() ?? '(ohne Namen)', $fleet->getOwner()->getUsername(), $where, $home->getName(), $home->getCoordinates()));
            $count += null === $home ? 0 : 1;
        }
        $io->success(\sprintf($dryRun ? '%d Flotte(n) stehen auf freien Plätzen.' : '%d Flotte(n) heimgeschickt.', $count));

        return Command::SUCCESS;
    }
}
