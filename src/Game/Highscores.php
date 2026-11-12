<?php

namespace App\Game;

use App\Entity\Alliance;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rankings of players and alliances by points, combat experience, credits (traders like
 * to compare those), optionally only one life form. An alliance counts the sums of its members.
 */
final readonly class Highscores
{
    public const string BY_POINTS = 'points';
    public const string BY_EXPERIENCE = 'experience';
    public const string BY_CREDITS = 'credits';
    public const string BY_PLANETS = 'planets';

    /** The sort keys, in the order they break ties. */
    public const array SORTS = [self::BY_POINTS, self::BY_EXPERIENCE, self::BY_CREDITS, self::BY_PLANETS];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<array{rank: int, user: User, points: int, experience: float, credits: float, planets: int}> */
    public function players(string $sort, ?LifeForm $lifeForm = null): array
    {
        $rows = [];
        foreach ($this->allPlayers() as $user) {
            if (null === $lifeForm || $user->getLifeForm() === $lifeForm) {
                $rows[] = ['user' => $user, 'name' => $user->getUsername(), 'points' => Scoring::points($user), 'experience' => $user->getCombatExperience(), 'credits' => $user->getCredits(), 'planets' => self::planets($user)];
            }
        }

        return $this->rank($rows, $sort);
    }

    /** @return list<array{rank: int, alliance: Alliance, points: int, experience: float, credits: float, planets: int, members: int}> */
    public function alliances(string $sort, ?LifeForm $lifeForm = null): array
    {
        $rows = [];
        foreach ($this->allPlayers() as $user) {
            $alliance = $user->getAlliance();
            if (null === $alliance || (null !== $lifeForm && $alliance->getLifeForm() !== $lifeForm)) {
                continue;
            }
            $id = (int) $alliance->getId();
            $rows[$id] ??= ['alliance' => $alliance, 'name' => $alliance->getName(), 'points' => 0, 'experience' => 0.0, 'credits' => 0.0, 'planets' => 0, 'members' => 0];
            $rows[$id]['points'] += Scoring::points($user);
            $rows[$id]['experience'] += $user->getCombatExperience();
            $rows[$id]['credits'] += $user->getCredits();
            $rows[$id]['planets'] += self::planets($user);
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
            ->where('u.system = false')
            ->getQuery()
            ->getResult();
    }

    /**
     * Sorts descending by the chosen value (then by the others, then by name) and numbers
     * the rows; equal values share a rank.
     *
     * @template T of array{name: string, points: int, experience: float, credits: float, planets: int}
     *
     * @param list<T> $rows
     *
     * @return list<T&array{rank: int}>
     */
    private function rank(array $rows, string $sort): array
    {
        $keys = [$sort, ...array_values(array_diff(self::SORTS, [$sort]))];
        $key = static fn (array $row, array $named): array => [...array_map(static fn (string $k): float => (float) $row[$k], $keys), mb_strtolower($named['name'])];
        usort($rows, static fn (array $a, array $b): int => $key($b, $a) <=> $key($a, $b));

        $ranked = [];
        $previous = null;
        $rank = 0;
        foreach ($rows as $i => $row) {
            $value = (int) floor($row[$sort]);
            if ($value !== $previous) {
                $rank = $i + 1;
                $previous = $value;
            }
            $ranked[] = ['rank' => $rank] + $row;
        }

        return $ranked;
    }

    /** Planets of a player; welded ones count one by one (a fusion of 3 is 3 planets). */
    private static function planets(User $user): int
    {
        return array_sum(array_map(static fn (\App\Entity\Planet $p): int => $p->getFusionSize(), $user->getPlanets()->toArray()));
    }
}
