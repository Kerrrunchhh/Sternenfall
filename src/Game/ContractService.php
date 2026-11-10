<?php

namespace App\Game;

use App\Entity\Contract;
use App\Entity\Planet;
use App\Entity\User;
use App\Repository\ContractRepository;
use App\Repository\PlanetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Contracts: a client offers a cash reward (held in escrow) for protecting one of his
 * planets or for invading a planet; players apply, the client picks one. Invasion is
 * fulfilled when the contractor conquers the target slot as a single planet (a fusion
 * has to be blown up first); protection when the planet still belongs to the client at
 * the end. Otherwise the client gets his money back.
 */
final readonly class ContractService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContractRepository $contracts,
        private PlanetRepository $planets,
        private Inbox $inbox,
        private ClockInterface $clock,
    ) {
    }

    public function create(User $client, string $kind, int $galaxy, int $system, int $position, float $reward, int $days, string $note): Contract
    {
        if (!\in_array($kind, [Contract::PROTECTION, Contract::INVASION], true)) {
            throw new GameException('Wähle Planetenschutz oder Invasion.');
        }
        if ($days < Contract::MIN_DAYS || $days > Contract::MAX_DAYS) {
            throw new GameException(\sprintf('Ein Auftrag dauert %d bis %d Tage.', Contract::MIN_DAYS, Contract::MAX_DAYS));
        }
        $reward = floor($reward);
        if ($reward < 1) {
            throw new GameException('Die Belohnung muss mindestens 1 Credit sein.');
        }
        $planet = $this->planets->findAt($galaxy, $system, $position) ?? throw new GameException('Dort gibt es keinen Planeten.');
        if (Contract::PROTECTION === $kind && !$planet->getOwner()->isSameAs($client)) {
            throw new GameException('Schutz kannst du nur für deine eigenen Planeten in Auftrag geben.');
        }
        if (Contract::INVASION === $kind && ($planet->getOwner()->isSameAs($client) || $client->isAllyOf($planet->getOwner()))) {
            throw new GameException('Eigene und verbündete Planeten lässt du nicht invadieren.');
        }
        $this->escrow($client, $reward);

        $contract = new Contract($client, $kind, $planet, $reward, $days, $note, $this->now());
        $this->em->persist($contract);
        $this->em->flush();

        return $contract;
    }

    public function apply(Contract $contract, User $applicant, string $message): void
    {
        if (Contract::OPEN !== $contract->getStatus()) {
            throw new GameException('Für diesen Auftrag kann man sich nicht mehr bewerben.');
        }
        if ($contract->getClient()->isSameAs($applicant)) {
            throw new GameException('Auf deinen eigenen Auftrag bewirbst du dich nicht.');
        }
        if (null !== $contract->applicationOf($applicant)) {
            throw new GameException('Du hast dich schon beworben.');
        }
        $contract->apply($applicant, $message, $this->now());
        $this->inbox->send($contract->getClient(), 'Bewerbung auf deinen Auftrag', \sprintf("%s bewirbt sich auf deinen Auftrag „%s“ für %s [%s].%s\n\nAnnehmen kannst du unter Aufträge.", $applicant->getUsername(), $contract->kindLabel(), $contract->getPlanetName(), $contract->getCoordinates(), '' === trim($message) ? '' : "\n\n> ".trim($message)));
        $this->em->flush();
    }

    public function accept(Contract $contract, User $client, int $applicationId): void
    {
        if (!$contract->getClient()->isSameAs($client) || Contract::OPEN !== $contract->getStatus()) {
            throw new GameException('Diesen Auftrag kannst du nicht (mehr) vergeben.');
        }
        $application = $contract->getApplications()->findFirst(static fn (int $k, $a): bool => $a->getId() === $applicationId)
            ?? throw new GameException('Diese Bewerbung gibt es nicht.');
        $contract->assign($application->getApplicant(), $this->now());
        foreach ($contract->getApplications() as $other) {
            $won = $other === $application;
            $this->inbox->send($other->getApplicant(), $won ? 'Auftrag erhalten: '.$contract->kindLabel() : 'Auftrag vergeben', $won
                ? \sprintf("%s hat dir den Auftrag gegeben: %s für %s [%s], Belohnung %s Credits, bis %s.\n%s", $client->getUsername(), $contract->kindLabel(), $contract->getPlanetName(), $contract->getCoordinates(), number_format($contract->getReward(), 0, ',', '.'), $contract->getEndsAt()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i'), $contract->isProtection() ? 'Du darfst so lange Schiffe auf dem Planeten stationieren.' : 'Bei einer Fusion: erst sprengen, dann den obersten Planeten invadieren.')
                : \sprintf('Der Auftrag „%s“ für %s [%s] ging an jemand anderen.', $contract->kindLabel(), $contract->getPlanetName(), $contract->getCoordinates()));
        }
        $this->em->flush();
    }

    public function cancel(Contract $contract, User $client): void
    {
        if (!$contract->getClient()->isSameAs($client) || Contract::OPEN !== $contract->getStatus()) {
            throw new GameException('Zurückziehen geht nur, solange der Auftrag noch nicht vergeben ist.');
        }
        $contract->close(Contract::CANCELLED);
        $this->credit($client, $contract->getReward());
        $this->em->flush();
    }

    /** The contractor may keep ships on the protected planet as long as the contract runs. */
    public function protects(User $fleetOwner, Planet $planet): bool
    {
        foreach ($this->contracts->findRunningIn($planet->getGalaxy(), $planet->getSystem()) as $contract) {
            if ($contract->isProtection() && $contract->isOn($planet) && true === $contract->getContractor()?->isSameAs($fleetOwner)) {
                return true;
            }
        }

        return false;
    }

    /** Called after an invasion: pays invasion contracts the conqueror just fulfilled. */
    public function conquered(Planet $planet, User $conqueror): void
    {
        foreach ($this->contracts->findRunningIn($planet->getGalaxy(), $planet->getSystem()) as $contract) {
            if (!$contract->isProtection() && $contract->isOn($planet) && 1 === $planet->getFusionSize()
                && true === $contract->getContractor()?->isSameAs($conqueror)) {
                $contract->close(Contract::DONE);
                $this->credit($conqueror, $contract->getReward());
                $this->inbox->send($conqueror, 'Auftrag erfüllt', \sprintf('Du hast %s [%s] erobert – %s Credits Belohnung von %s.', $planet->getName(), $contract->getCoordinates(), number_format($contract->getReward(), 0, ',', '.'), $contract->getClient()->getUsername()));
                $this->inbox->send($contract->getClient(), 'Auftrag erfüllt', \sprintf('%s hat %s [%s] erobert und die Belohnung erhalten.', $conqueror->getUsername(), $planet->getName(), $contract->getCoordinates()));
            }
        }
    }

    /** Contracts whose time is up: protection paid if the planet is still the client's, otherwise refunds. */
    public function settleDue(): void
    {
        foreach ($this->contracts->findDue($this->now()) as $contract) {
            $planet = $this->planets->findAt($contract->getGalaxy(), $contract->getSystem(), $contract->getPosition());
            $contractor = $contract->getContractor();
            if ($contract->isProtection() && null !== $contractor && true === $planet?->getOwner()->isSameAs($contract->getClient())) {
                $contract->close(Contract::DONE);
                $this->credit($contractor, $contract->getReward());
                $this->inbox->send($contractor, 'Auftrag erfüllt', \sprintf('%s [%s] gehört nach wie vor %s – %s Credits für den Schutz.', $contract->getPlanetName(), $contract->getCoordinates(), $contract->getClient()->getUsername(), number_format($contract->getReward(), 0, ',', '.')));
            } else {
                $contract->close(Contract::FAILED);
                $this->credit($contract->getClient(), $contract->getReward());
                if (null !== $contractor) {
                    $this->inbox->send($contractor, 'Auftrag nicht erfüllt', \sprintf('Die Zeit für „%s“ bei %s [%s] ist um. Die Belohnung geht an %s zurück.', $contract->kindLabel(), $contract->getPlanetName(), $contract->getCoordinates(), $contract->getClient()->getUsername()));
                }
            }
            $this->inbox->send($contract->getClient(), 'Auftrag beendet: '.$contract->statusLabel(), \sprintf('„%s“ für %s [%s] ist beendet: %s.', $contract->kindLabel(), $contract->getPlanetName(), $contract->getCoordinates(), Contract::DONE === $contract->getStatus() ? 'erfüllt, die Belohnung ist ausgezahlt' : 'nicht erfüllt, du bekommst die Belohnung zurück'));
        }
        $this->em->flush();
    }

    /** Takes credits from a player atomically; fails if he has too few. */
    private function escrow(User $user, float $amount): void
    {
        $taken = $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits - ? WHERE id = ? AND credits >= ?', [$amount, $user->getId(), $amount]);
        $this->em->refresh($user);
        if (0 === $taken) {
            throw new GameException(\sprintf('Du hast nur %s Credits.', number_format($user->getCredits(), 0, ',', '.')));
        }
    }

    private function credit(User $user, float $amount): void
    {
        $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits + ? WHERE id = ?', [$amount, $user->getId()]);
    }

    private function now(): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
