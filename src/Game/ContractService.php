<?php

namespace App\Game;

use App\Entity\Contract;
use App\Entity\Planet;
use App\Entity\User;
use App\Repository\ContractRepository;
use App\Repository\PlanetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

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
            throw new GameException(t('Wähle Planetenschutz oder Invasion.'));
        }
        if ($days < Contract::MIN_DAYS || $days > Contract::MAX_DAYS) {
            throw new GameException(t('Ein Auftrag dauert {value} bis {value2} Tage.', ['{value}' => Contract::MIN_DAYS, '{value2}' => Contract::MAX_DAYS]));
        }
        $reward = floor($reward);
        if ($reward < 1) {
            throw new GameException(t('Die Belohnung muss mindestens 1 Credit sein.'));
        }
        $planet = $this->planets->findAt($galaxy, $system, $position) ?? throw new GameException(t('Dort gibt es keinen Planeten.'));
        if (Contract::PROTECTION === $kind && !$planet->getOwner()->isSameAs($client)) {
            throw new GameException(t('Schutz kannst du nur für deine eigenen Planeten in Auftrag geben.'));
        }
        if (Contract::INVASION === $kind && ($planet->getOwner()->isSameAs($client) || $client->isAllyOf($planet->getOwner()))) {
            throw new GameException(t('Eigene und verbündete Planeten lässt du nicht invadieren.'));
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
            throw new GameException(t('Für diesen Auftrag kann man sich nicht mehr bewerben.'));
        }
        if ($contract->getClient()->isSameAs($applicant)) {
            throw new GameException(t('Auf deinen eigenen Auftrag bewirbst du dich nicht.'));
        }
        if (null !== $contract->applicationOf($applicant)) {
            throw new GameException(t('Du hast dich schon beworben.'));
        }
        $contract->apply($applicant, $message, $this->now());
        $this->inbox->send($contract->getClient(), t('Bewerbung auf deinen Auftrag'), t("{player} bewirbt sich auf deinen Auftrag „{kindLabel}“ für {planetName} [{coords}].{value}\n\nAnnehmen kannst du unter Aufträge.", ['{player}' => $applicant->getUsername(), '{kindLabel}' => t($contract->kindLabel()), '{planetName}' => $contract->getPlanetName(), '{coords}' => $contract->getCoordinates(), '{value}' => '' === trim($message) ? '' : "\n\n> ".trim($message)]));
        $this->em->flush();
    }

    public function accept(Contract $contract, User $client, int $applicationId): void
    {
        if (!$contract->getClient()->isSameAs($client) || Contract::OPEN !== $contract->getStatus()) {
            throw new GameException(t('Diesen Auftrag kannst du nicht (mehr) vergeben.'));
        }
        $application = $contract->getApplications()->findFirst(static fn (int $k, $a): bool => $a->getId() === $applicationId)
            ?? throw new GameException(t('Diese Bewerbung gibt es nicht.'));
        $contract->assign($application->getApplicant(), $this->now());
        foreach ($contract->getApplications() as $other) {
            $won = $other === $application;
            $this->inbox->send($other->getApplicant(), $won ? t('Auftrag erhalten: {kind}', ['{kind}' => t($contract->kindLabel())]) : t('Auftrag vergeben'), $won
                ? t("{player} hat dir den Auftrag gegeben: {kindLabel} für {planetName} [{coords}], Belohnung {reward} Credits, bis {value}.\n{value2}", ['{player}' => $client->getUsername(), '{kindLabel}' => t($contract->kindLabel()), '{planetName}' => $contract->getPlanetName(), '{coords}' => $contract->getCoordinates(), '{reward}' => new Num($contract->getReward()), '{value}' => $contract->getEndsAt()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i'), '{value2}' => $contract->isProtection() ? t('Du darfst so lange Schiffe auf dem Planeten stationieren.') : t('Bei einer Fusion: erst sprengen, dann den obersten Planeten invadieren.')])
                : t('Der Auftrag „{kindLabel}“ für {planetName} [{coords}] ging an jemand anderen.', ['{kindLabel}' => t($contract->kindLabel()), '{planetName}' => $contract->getPlanetName(), '{coords}' => $contract->getCoordinates()]));
        }
        $this->em->flush();
    }

    public function cancel(Contract $contract, User $client): void
    {
        if (!$contract->getClient()->isSameAs($client) || Contract::OPEN !== $contract->getStatus()) {
            throw new GameException(t('Zurückziehen geht nur, solange der Auftrag noch nicht vergeben ist.'));
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
                $this->inbox->send($conqueror, t('Auftrag erfüllt'), t('Du hast {name} [{coords}] erobert – {reward} Credits Belohnung von {player}.', ['{name}' => $planet->getName(), '{coords}' => $contract->getCoordinates(), '{reward}' => new Num($contract->getReward()), '{player}' => $contract->getClient()->getUsername()]));
                $this->inbox->send($contract->getClient(), t('Auftrag erfüllt'), t('{player} hat {name} [{coords}] erobert und die Belohnung erhalten.', ['{player}' => $conqueror->getUsername(), '{name}' => $planet->getName(), '{coords}' => $contract->getCoordinates()]));
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
                $this->inbox->send($contractor, t('Auftrag erfüllt'), t('{planetName} [{coords}] gehört nach wie vor {player} – {reward} Credits für den Schutz.', ['{planetName}' => $contract->getPlanetName(), '{coords}' => $contract->getCoordinates(), '{player}' => $contract->getClient()->getUsername(), '{reward}' => new Num($contract->getReward())]));
            } else {
                $contract->close(Contract::FAILED);
                $this->credit($contract->getClient(), $contract->getReward());
                if (null !== $contractor) {
                    $this->inbox->send($contractor, t('Auftrag nicht erfüllt'), t('Die Zeit für „{kindLabel}“ bei {planetName} [{coords}] ist um. Die Belohnung geht an {player} zurück.', ['{kindLabel}' => t($contract->kindLabel()), '{planetName}' => $contract->getPlanetName(), '{coords}' => $contract->getCoordinates(), '{player}' => $contract->getClient()->getUsername()]));
                }
            }
            $this->inbox->send($contract->getClient(), t('Auftrag beendet: {status}', ['{status}' => t($contract->statusLabel())]), t('„{kindLabel}“ für {planetName} [{coords}] ist beendet: {value}.', ['{kindLabel}' => t($contract->kindLabel()), '{planetName}' => $contract->getPlanetName(), '{coords}' => $contract->getCoordinates(), '{value}' => Contract::DONE === $contract->getStatus() ? t('erfüllt, die Belohnung ist ausgezahlt') : t('nicht erfüllt, du bekommst die Belohnung zurück')]));
        }
        $this->em->flush();
    }

    /** Takes credits from a player atomically; fails if he has too few. */
    private function escrow(User $user, float $amount): void
    {
        $taken = $this->em->getConnection()->executeStatement('UPDATE `user` SET credits = credits - ? WHERE id = ? AND credits >= ?', [$amount, $user->getId(), $amount]);
        $this->em->refresh($user);
        if (0 === $taken) {
            throw new GameException(t('Du hast nur {credits} Credits.', ['{credits}' => new Num($user->getCredits())]));
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
