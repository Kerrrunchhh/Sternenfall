<?php

namespace App\Game;

use App\Entity\BattleReport;
use App\Entity\Planet;
use App\Entity\User;
use App\I18n\Lines;
use App\I18n\Num;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

use function Symfony\Component\Translation\t;

/**
 * Admins annul a combat report (e.g. after a server problem) and give ships back: any
 * number of each type, per player, into the hangar of one of his planets.
 */
final readonly class BattleReportRefunds
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlanetService $planets,
        private UserRepository $users,
        private Inbox $inbox,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<int, array<string, int|string>> $ships   user id => ship type => count
     * @param array<int, int|string>                $targets user id => planet id
     *
     * @return int how many players got ships back
     */
    public function annul(BattleReport $report, string $reason, array $ships, array $targets): int
    {
        if ('' === trim($reason)) {
            throw new GameException(t('Gib einen Grund an – die Beteiligten bekommen ihn mitgeteilt.'));
        }
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        // First check everything, then book: either all refunds happen or none.
        $bookings = [];
        foreach ($ships as $userId => $counts) {
            $counts = array_filter(array_map('intval', array_intersect_key($counts, array_flip(array_map(static fn (ShipType $t): string => $t->value, ShipType::cases())))), static fn (int $n): bool => $n > 0);
            if ([] === $counts) {
                continue;
            }
            $user = $this->users->find((int) $userId) ?? throw new GameException(t('Diesen Spieler gibt es nicht mehr.'));
            $planet = $user->getPlanets()->findFirst(static fn (int $k, Planet $p): bool => $p->getId() === (int) ($targets[$userId] ?? 0))
                ?? throw new GameException(t('Wähle für {player} einen seiner Planeten.', ['{player}' => $user->getUsername()]));
            $bookings[] = [$user, $planet, $counts];
        }

        $report->annul($reason, $now);
        foreach ($bookings as [$user, $planet, $counts]) {
            $this->planets->transaction($planet, static function (Planet $planet) use ($counts): void {
                foreach ($counts as $type => $count) {
                    $planet->addShips(ShipType::from($type), $count);
                }
            });
            $report->addRefund((int) $user->getId(), $user->getUsername(), (int) $planet->getId(), $planet->getName(), $counts, $now);
            $this->inbox->send($user, t('Schiffe erstattet'), t("Die Spielleitung hat einen Kampfbericht annulliert ({reason}) und dir Schiffe erstattet. Sie stehen im Hangar von {planet} [{coords}]:\n{ships}", ['{reason}' => trim($reason), '{planet}' => $planet->getName(), '{coords}' => $planet->getCoordinates(), '{ships}' => new Lines(array_map(static fn (string $type, int $count): \Symfony\Contracts\Translation\TranslatableInterface => t('{count} × {type}', ['{count}' => new Num($count), '{type}' => t(ShipType::from($type)->label())]), array_keys($counts), $counts))]));
        }
        // Everybody involved learns that the report no longer counts.
        foreach (array_keys($report->getLosses()) as $userId) {
            $user = $this->users->find($userId);
            if (null !== $user && [] === array_filter($bookings, static fn (array $b): bool => $b[0]->isSameAs($user))) {
                $this->inbox->send($user, t('Kampfbericht annulliert'), t('Die Spielleitung hat den Kampfbericht „{subject}“ annulliert: {reason}', ['{subject}' => $report->getSubject(), '{reason}' => trim($reason)]));
            }
        }
        $this->em->flush();

        return \count($bookings);
    }

    /** @return list<User> the players of the report (from its stored losses) */
    public function participants(BattleReport $report): array
    {
        return array_values(array_filter(array_map(fn (int $id): ?User => $this->users->find($id), array_keys($report->getLosses()))));
    }
}
