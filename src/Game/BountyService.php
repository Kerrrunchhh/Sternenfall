<?php

namespace App\Game;

use App\I18n\When;
use App\Entity\Bounty;
use App\Entity\User;
use App\I18n\Num;
use App\Repository\BountyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Bounties: a client puts credits (held in escrow) on a player. Every hunter collects the
 * shield points of the target's spaceships he destroys, over any number of battles; the
 * first to reach the required amount gets the reward. Unclaimed bounties are refunded.
 */
final readonly class BountyService
{
    /** A small fleet as an example for choosing the shield points (shields without research). */
    public const array EXAMPLE_FLEET = ['pfeil' => 20, 'falke' => 10, 'lanze' => 5];

    public function __construct(
        private EntityManagerInterface $em,
        private BountyRepository $bounties,
        private UserRepository $users,
        private Inbox $inbox,
        private ClockInterface $clock,
    ) {
    }

    /** Shield points of the example fleet: what destroying it would count. */
    public static function exampleShield(): int
    {
        $shield = 0;
        foreach (self::EXAMPLE_FLEET as $type => $count) {
            $shield += $count * ShipType::from($type)->spec()->shield;
        }

        return $shield;
    }

    public function create(User $client, string $targetName, float $reward, float $requiredShield, int $days, string $note): Bounty
    {
        $target = $this->users->findOneBy(['username' => trim($targetName)]);
        if (null === $target || $target->isSystem()) {
            throw new GameException(t('Einen Spieler „{name}“ gibt es nicht.', ['{name}' => trim($targetName)]));
        }
        if ($target->isSameAs($client)) {
            throw new GameException(t('Auf dich selbst setzt du kein Kopfgeld aus.'));
        }
        if ($client->isAllyOf($target)) {
            throw new GameException(t('Auf Mitglieder deiner Allianz setzt du kein Kopfgeld aus.'));
        }
        if ($days < Bounty::MIN_DAYS || $days > Bounty::MAX_DAYS) {
            throw new GameException(t('Ein Kopfgeld gilt {value} bis {value2} Tage.', ['{value}' => Bounty::MIN_DAYS, '{value2}' => Bounty::MAX_DAYS]));
        }
        $requiredShield = floor($requiredShield);
        if ($requiredShield < 1) {
            throw new GameException(t('Gib an, wie viele Schildpunkte zerstört werden müssen (mindestens 1).'));
        }
        $reward = floor($reward);
        if ($reward < 1) {
            throw new GameException(t('Das Kopfgeld muss mindestens 1 Credit sein.'));
        }
        $this->escrow($client, $reward);

        $bounty = new Bounty($client, $target, $reward, $requiredShield, $days, $note, $this->now());
        $this->em->persist($bounty);
        $this->inbox->send($target, t('Kopfgeld auf dich ausgesetzt'), t('Auf dich ist ein Kopfgeld von {reward} Credits ausgesetzt: Wer {shield} Schildpunkte deiner Raumschiffe zerstört, bekommt es. Es gilt bis {endsAt}.', ['{reward}' => new Num($reward), '{shield}' => new Num($requiredShield), '{endsAt}' => new When($bounty->getEndsAt())]));
        $this->em->flush();

        return $bounty;
    }

    /** Withdrawing is only possible while nobody has destroyed anything yet. */
    public function cancel(Bounty $bounty, User $client): void
    {
        if (!$bounty->getClient()->isSameAs($client) || !$bounty->isOpen()) {
            throw new GameException(t('Dieses Kopfgeld kannst du nicht (mehr) zurückziehen.'));
        }
        if (!$bounty->getHunts()->isEmpty()) {
            throw new GameException(t('Zurückziehen geht nicht mehr: Jäger haben schon Schiffe von {player} zerstört.', ['{player}' => $bounty->getTarget()->getUsername()]));
        }
        $bounty->close(Bounty::CANCELLED);
        $this->credit($client, $bounty->getReward());
        $this->em->flush();
    }

    /**
     * Books a battle: destroyed spaceship shields of every bounty target in it count for the
     * hunters on the other side. Pays the bounties that are reached.
     *
     * @return list<TranslatableInterface> lines for the battle report
     */
    public function record(BattleResult $result): array
    {
        $lines = [];
        foreach ($result->participants() as $target) {
            $bounties = $this->bounties->findOpenOn($target);
            if ([] === $bounties) {
                continue;
            }
            $shares = $result->shipShieldDestroyedOf($target);
            foreach ($bounties as $bounty) {
                foreach ($shares as ['user' => $hunter, 'shield' => $shield]) {
                    if (!$bounty->isOpen() || $shield <= 0 || $hunter->isSameAs($bounty->getClient()) || $hunter->isSameAs($target)) {
                        continue;
                    }
                    $hunt = $bounty->record($hunter, $shield);
                    if (null === $hunt->getId()) {
                        $this->em->persist($hunt);
                    }
                    if ($hunt->getShield() >= $bounty->getRequiredShield()) {
                        $this->pay($bounty, $hunter);
                        $lines[] = t('  Kopfgeld auf {target}: {hunter} hat es verdient – {reward} Credits.', ['{target}' => $target->getUsername(), '{hunter}' => $hunter->getUsername(), '{reward}' => new Num($bounty->getReward())]);
                    } else {
                        $lines[] = t('  Kopfgeld auf {target}: {hunter} hat {shield} von {required} Schildpunkten.', ['{target}' => $target->getUsername(), '{hunter}' => $hunter->getUsername(), '{shield}' => new Num(floor($hunt->getShield())), '{required}' => new Num($bounty->getRequiredShield())]);
                    }
                }
            }
        }

        return [] === $lines ? [] : ['', t('Kopfgeld:'), ...$lines];
    }

    /** Bounties whose time is up go back to their clients. */
    public function settleDue(): void
    {
        foreach ($this->bounties->findDue($this->now()) as $bounty) {
            $bounty->close(Bounty::EXPIRED);
            $this->credit($bounty->getClient(), $bounty->getReward());
            $this->inbox->send($bounty->getClient(), t('Kopfgeld abgelaufen'), t('Niemand hat das Kopfgeld auf {player} verdient. Du bekommst die {reward} Credits zurück.', ['{player}' => $bounty->getTarget()->getUsername(), '{reward}' => new Num($bounty->getReward())]));
        }
        $this->em->flush();
    }

    private function pay(Bounty $bounty, User $hunter): void
    {
        $bounty->award($hunter);
        // The hunter is loaded (and may just have earned credits) during the battle: book it on the entity, flushed with the battle.
        $hunter->addCredits($bounty->getReward());
        $this->inbox->send($hunter, t('Kopfgeld verdient'), t('Du hast {shield} Schildpunkte der Raumschiffe von {player} zerstört und bekommst das Kopfgeld von {client}: {reward} Credits.', ['{shield}' => new Num($bounty->getRequiredShield()), '{player}' => $bounty->getTarget()->getUsername(), '{client}' => $bounty->getClient()->getUsername(), '{reward}' => new Num($bounty->getReward())]));
        $this->inbox->send($bounty->getClient(), t('Kopfgeld vergeben'), t('{hunter} hat dein Kopfgeld auf {player} verdient ({reward} Credits).', ['{hunter}' => $hunter->getUsername(), '{player}' => $bounty->getTarget()->getUsername(), '{reward}' => new Num($bounty->getReward())]));
        $this->inbox->send($bounty->getTarget(), t('Kopfgeld auf dich vergeben'), t('{hunter} hat das Kopfgeld von {client} auf dich kassiert.', ['{hunter}' => $hunter->getUsername(), '{client}' => $bounty->getClient()->getUsername()]));
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
