<?php

namespace App\Game;

use App\Entity\Bounty;
use App\Entity\Contract;
use App\Entity\FusionatorOffer;
use App\Entity\TransportRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function Symfony\Component\Translation\t;

/**
 * Admins deactivate players instead of deleting them: the account stays (research, credits,
 * experience, messages), but its planets, fleets and trade posts become ownerless like on
 * deletion, it leaves its alliance and its open offers are withdrawn. A deactivated player
 * can't log in, gets no mails and is left out of highscores and the player search.
 * Reactivating gives him a new home world.
 */
final readonly class AccountDeactivation
{
    public function __construct(
        private EntityManagerInterface $em,
        private AccountDeletion $deletion,
        private Colonizer $colonizer,
        private ContractService $contracts,
        private BountyService $bounties,
        private TransportRequests $transportRequests,
        private FusionatorMarket $fusionators,
        private ClockInterface $clock,
    ) {
    }

    public function deactivate(User $user, bool $destroyBuildings): void
    {
        if ($user->isSystem() || $user->isDeactivated()) {
            throw new GameException(t('Dieses Konto kann nicht deaktiviert werden.'));
        }
        $this->withdrawOffers($user);
        $this->deletion->abandon($user, $destroyBuildings);
        $user->cancelDeletion();
        $user->deactivate(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->em->flush();
    }

    public function reactivate(User $user): void
    {
        if (!$user->isDeactivated()) {
            throw new GameException(t('Dieses Konto ist nicht deaktiviert.'));
        }
        $user->reactivate();
        if ($user->getPlanets()->isEmpty()) {
            $this->colonizer->createHomePlanet($user);
        }
        $this->em->flush();
    }

    /** Open contracts, bounties, transport requests and fusionator offers go back to him (credits, fusionators). */
    private function withdrawOffers(User $user): void
    {
        foreach ($this->em->getRepository(Contract::class)->findBy(['client' => $user, 'status' => Contract::OPEN]) as $contract) {
            $this->contracts->cancel($contract, $user);
        }
        foreach ($this->em->getRepository(Bounty::class)->findBy(['client' => $user, 'status' => Bounty::OPEN]) as $bounty) {
            try {
                $this->bounties->cancel($bounty, $user);
            } catch (GameException) {
                // Hunters already earned part of it: the bounty stays for them.
            }
        }
        foreach ($this->em->getRepository(TransportRequest::class)->findBy(['requester' => $user, 'status' => TransportRequest::OPEN]) as $request) {
            $this->transportRequests->cancel($request, $user);
        }
        foreach ($this->em->getRepository(FusionatorOffer::class)->findBy(['seller' => $user]) as $offer) {
            try {
                $this->fusionators->withdraw($offer, $user);
            } catch (GameException) {
                // No planet to take them back: the offer stays on the market.
            }
        }
    }
}
