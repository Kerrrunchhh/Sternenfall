<?php

namespace App\Game;

use App\Entity\Alliance;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rankings of players and alliances by points or combat experience, optionally
 * only one life form. An alliance counts the sums of its members.
 */
final readonly class Highscores
{
    public const string BY_POINTS = 'points';
    public const string BY_EXPERIENCE = 'experience';

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<array{rank: int, user: User, points: int, experience: float}> */
    public function players(string $sort, ?LifeForm $lifeForm = null): array
    {
        $rows = [];
        foreach ($this->allPlayers() as $user) {
            if (null === $lifeForm || $user->getLifeForm() === $lifeForm) {
                $rows[] = ['user' => $user, 'name' => $user->getUsername(), 'points' => Scoring::points($user), 'experience' => $user->getCombatExperience()];
            }
        }

        return $this->rank($rows, $sort);
    }

    /** @return list<array{rank: int, alliance: Alliance, points: int, experience: float, members: int}> */
    public function alliances(string $sort, ?LifeForm $lifeForm = null): array
    {
        $rows = [];
        foreach ($this->allPlayers() as $user) {
            $alliance = $user->getAlliance();
            if (null === $alliance || (null !== $lifeForm && $alliance->getLifeForm() !== $lifeForm)) {
                continue;
            }
            $id = (int) $alliance->getId();
            $rows[$id] ??= ['alliance' => $alliance, 'name' => $alliance->getName(), 'points' => 0, 'experience' => 0.0, 'members' => 0];
            $rows[$id]['points'] += Scoring::points($user);
            $rows[$id]['experience'] += $user->getCombatExperience();
            ++$rows[$id]['members'];
        }

        return $this->rank(array_values($rows), $sort);
    }

    /** @return list<User> all players with their planets in one query */
    private function allPlayers(): array
    {
        return $this->em->createQueryBuilder()
            ->select('u', 'p', 'a')
            ->from(User::class, 'u')
            ->leftJoin('u.planets', 'p')
            ->leftJoin('u.alliance', 'a')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sorts descending by the chosen value (then by the other one, then by name) and numbers
     * the rows; equal values share a rank.
     *
     * @template T of array{name: string, points: int, experience: float}
     *
     * @param list<T> $rows
     *
     * @return list<T&array{rank: int}>
     */
    private function rank(array $rows, string $sort): array
    {
        $other = self::BY_POINTS === $sort ? self::BY_EXPERIENCE : self::BY_POINTS;
        usort($rows, static fn (array $a, array $b): int => [$b[$sort], $b[$other], mb_strtolower($a['name'])] <=> [$a[$sort], $a[$other], mb_strtolower($b['name'])]);

        $ranked = [];
        $previous = null;
        $rank = 0;
        foreach ($rows as $i => $row) {
            $value = self::BY_EXPERIENCE === $sort ? (int) floor($row[$sort]) : $row[$sort];
            if ($value !== $previous) {
                $rank = $i + 1;
                $previous = $value;
            }
            $ranked[] = ['rank' => $rank] + $row;
        }

        return $ranked;
    }
}
