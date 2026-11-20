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
    public const string BY_RESEARCH = 'research';

    public const array SORTS = [self::BY_POINTS, self::BY_RESEARCH, self::BY_EXPERIENCE, self::BY_CREDITS, self::BY_PLANETS];
    /** Ties of the chosen value are broken by these, then by who was in the game first. */
    public const array TIE_BREAKERS = [self::BY_PLANETS, self::BY_POINTS];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @return list<array{rank: int, user: User, points: int, experience: float, credits: float, planets: int}> */
    public function players(string $sort, ?LifeForm $lifeForm = null): array
    {
        return $this->rank($this->playerRows($lifeForm), $sort);
    }

    /**
     * The player's value and place in every ranking (for his profile); null if he isn't ranked
     * (system accounts, deactivated players, pending deletion).
     *
     * @return array<string, array{value: float, rank: int, of: int}>|null sort => value, place, number of players
     */
    public function standingOf(User $player): ?array
    {
        $rows = $this->playerRows();
        $standing = [];
        foreach (self::SORTS as $sort) {
            foreach ($this->rank($rows, $sort) as $row) {
                if ($row['user']->isSameAs($player)) {
                    $standing[$sort] = ['value' => (float) $row[$sort], 'rank' => $row['rank'], 'of' => \count($rows)];
                    continue 2;
                }
            }

            return null;
        }

        return $standing;
    }

    /** @return list<array{user: User, name: string, points: int, research: int, experience: float, credits: float, planets: int, since: int}> */
    private function playerRows(?LifeForm $lifeForm = null): array
    {
        $rows = [];
        foreach ($this->allPlayers() as $user) {
            if (null === $lifeForm || $user->getLifeForm() === $lifeForm) {
                $rows[] = ['user' => $user, 'name' => $user->getUsername(), 'points' => Scoring::points($user), 'research' => Scoring::researchPoints($user), 'experience' => $user->getCombatExperience(), 'credits' => $user->getCredits(), 'planets' => self::planets($user), 'since' => $user->getCreatedAt()->getTimestamp()];
            }
        }

        return $rows;
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
            $rows[$id] ??= ['alliance' => $alliance, 'name' => $alliance->getName(), 'points' => 0, 'research' => 0, 'experience' => 0.0, 'credits' => 0.0, 'planets' => 0, 'members' => 0, 'since' => $alliance->getCreatedAt()->getTimestamp()];
            $rows[$id]['points'] += Scoring::points($user);
            $rows[$id]['research'] += Scoring::researchPoints($user);
            $rows[$id]['experience'] += $user->getCombatExperience();
            $rows[$id]['credits'] += $user->getCredits();
            $rows[$id]['planets'] += self::planets($user);
            ++$rows[$id]['members'];
        }

        return $this->rank(array_values($rows), $sort);
    }

    /**
     * The best players of every research (level above 0), grouped by research category;
     * equal levels are ordered by points, then by name.
     *
     * @return array<string, list<array{research: ResearchType, leaders: list<array{user: User, level: int}>}>> category => researches
     */
    public function researchLeaders(?LifeForm $lifeForm = null, int $top = 3): array
    {
        $players = array_values(array_filter($this->allPlayers(), static fn (User $u): bool => null === $lifeForm || $u->getLifeForm() === $lifeForm));
        $points = [];
        foreach ($players as $user) {
            $points[spl_object_id($user)] = Scoring::points($user);
        }

        $groups = [];
        foreach (ResearchType::cases() as $research) {
            $ranked = array_values(array_filter($players, static fn (User $u): bool => $u->getResearchLevel($research) > 0));
            usort($ranked, static fn (User $a, User $b): int => [$b->getResearchLevel($research), $points[spl_object_id($b)], mb_strtolower($a->getUsername())]
                <=> [$a->getResearchLevel($research), $points[spl_object_id($a)], mb_strtolower($b->getUsername())]);
            $groups[$research->category()][] = [
                'research' => $research,
                'leaders' => array_map(static fn (User $u): array => ['user' => $u, 'level' => $u->getResearchLevel($research)], \array_slice($ranked, 0, $top)),
            ];
        }

        return $groups;
    }

    /** @return list<User> all players with their planets in one query */
    private function allPlayers(): array
    {
        $players = $this->em->createQueryBuilder()
            ->select('u', 'p', 'a')
            ->from(User::class, 'u')
            ->leftJoin('u.planets', 'p')
            ->leftJoin('u.alliance', 'a')
            ->where('u.system = false AND u.deactivatedAt IS NULL AND u.deleteAt IS NULL')
            ->getQuery()
            ->getResult();

        // Deactivated players and those whose deletion is pending are not ranked.
        return array_values(array_filter($players, static fn (User $u): bool => !$u->isDeactivated() && null === $u->getDeleteAt()));
    }

    /**
     * Sorts descending by the chosen value; ties go to more planets, then more points, then to
     * whoever was in the game first (player sign-up, alliance founding). Numbers the rows – a rank
     * is only shared when all of that is equal (then the name decides the order).
     *
     * @template T of array{name: string, points: int, experience: float, credits: float, planets: int, since: int}
     *
     * @param list<T> $rows
     *
     * @return list<T&array{rank: int}>
     */
    private function rank(array $rows, string $sort): array
    {
        $keys = [$sort, ...array_values(array_diff(self::TIE_BREAKERS, [$sort]))];
        // Descending values; the earlier start counts as more (hence negated).
        $standing = static fn (array $row): array => [...array_map(static fn (string $k): float => floor((float) $row[$k]), $keys), -$row['since']];
        usort($rows, static fn (array $a, array $b): int => [$standing($b), mb_strtolower($a['name'])] <=> [$standing($a), mb_strtolower($b['name'])]);

        $ranked = [];
        $previous = null;
        $rank = 0;
        foreach ($rows as $i => $row) {
            if ($standing($row) !== $previous) {
                $rank = $i + 1;
                $previous = $standing($row);
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
