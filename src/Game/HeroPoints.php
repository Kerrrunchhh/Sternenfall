<?php

namespace App\Game;

use App\Entity\User;
use App\I18n\Lines;
use App\I18n\Num;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Hero points: everybody starts with START; below 0 a player is criminal. The attacking
 * side of a battle earns them for every DEATHS_PER_POINT dead – bombed inhabitants and the
 * crews of destroyed spaceships: hero points if the victims belong to a pirate or a criminal,
 * crime points (negative) otherwise, in a war too – at most MAX_CRIME_PER_BATTLE per battle. Defending never costs points; intercepting
 * an attacking fleet counts as defending. Fighting pirates and criminals earns them back, and
 * without new crimes they recover by RECOVERY_PER_DAY up to START.
 *
 * Criminal guardians lose their double speed. Traders and guardians don't attack settlers and
 * traders who aren't criminal (except in a war) – unless they are criminal themselves.
 */
final class HeroPoints
{
    public const float START = 1000;
    public const int DEATHS_PER_POINT = 10;
    /** Most crime points one player gets for one battle: populations and fleets grow a lot during the game. */
    public const float MAX_CRIME_PER_BATTLE = 100;
    /** Without new crimes hero points come back by this much a day, up to START. */
    public const float RECOVERY_PER_DAY = 10;

    /** +1: fighting him is heroic, -1: it is a crime, 0: nobody to judge (ownerless planets). */
    public static function sign(User $victim): int
    {
        if ($victim->isSystem()) {
            return 0;
        }

        return LifeForm::Pirate === $victim->getLifeForm() || $victim->isCriminal() ? 1 : -1;
    }

    /**
     * Points each attacking player earns in a battle. Bombed inhabitants count for the players
     * whose heavy bombers survived (by their number), the crews of destroyed spaceships for the
     * whole attacking side (by build weight, like combat experience).
     *
     * @param bool $defensive intercepting an attack: only hero points, never crime points
     *
     * @return array<int, array{user: User, points: float}> keyed by spl_object_id of the user
     */
    public static function forBattle(BattleResult $result, float $bombed = 0, ?User $bombedOwner = null, bool $defensive = false): array
    {
        $earned = [];
        $add = static function (User $user, float $points) use (&$earned): void {
            $key = spl_object_id($user);
            $earned[$key] = ['user' => $user, 'points' => ($earned[$key]['points'] ?? 0.0) + $points];
        };
        $attackers = $result->side(BattleGroup::ATTACKER);

        // Crews of destroyed spaceships (turrets have no crew to count).
        $weights = [];
        foreach ($attackers as $group) {
            $key = spl_object_id($group->owner);
            $weights[$key] = ['user' => $group->owner, 'weight' => ($weights[$key]['weight'] ?? 0.0) + $group->buildWeight()];
        }
        $total = array_sum(array_column($weights, 'weight'));
        foreach ($result->side(BattleGroup::DEFENDER) as $group) {
            $deaths = $group->type->isStationary() ? 0 : $group->lost() * $group->type->spec()->crew;
            $sign = self::sign($group->owner);
            if ($deaths <= 0 || 0 === $sign || $total <= 0 || ($defensive && $sign < 0)) {
                continue;
            }
            foreach ($weights as ['user' => $user, 'weight' => $weight]) {
                $add($user, $sign * $deaths / self::DEATHS_PER_POINT * $weight / $total);
            }
        }

        // Bombed inhabitants.
        $sign = null === $bombedOwner ? 0 : self::sign($bombedOwner);
        if ($bombed > 0 && 0 !== $sign) {
            $bombers = [];
            foreach ($attackers as $group) {
                if (ShipType::HeavyBomber === $group->type && $group->alive() > 0) {
                    $key = spl_object_id($group->owner);
                    $bombers[$key] = ['user' => $group->owner, 'count' => ($bombers[$key]['count'] ?? 0) + $group->alive()];
                }
            }
            $count = array_sum(array_column($bombers, 'count'));
            foreach ($count > 0 ? $bombers : [] as ['user' => $user, 'count' => $mine]) {
                $add($user, $sign * $bombed / self::DEATHS_PER_POINT * $mine / $count);
            }
        }

        foreach ($earned as $key => $entry) {
            $earned[$key]['points'] = max(-self::MAX_CRIME_PER_BATTLE, $entry['points']);
        }

        return $earned;
    }

    /**
     * Books the points and describes them for the battle report.
     *
     * @param array<int, array{user: User, points: float}> $earned
     *
     * @return list<TranslatableInterface> report lines
     */
    public static function apply(array $earned): array
    {
        $lines = [];
        foreach ($earned as ['user' => $user, 'points' => $points]) {
            if (abs($points) < 0.5 || $user->isSystem()) {
                continue;
            }
            $wasCriminal = $user->isCriminal();
            $user->addHeroPoints($points);
            $lines[] = t('  {player} {points} (jetzt {total})', ['{player}' => $user->getUsername(), '{points}' => new Lines([$points > 0 ? '+' : '−', new Num(abs($points))], ''), '{total}' => new Num($user->getHeroPoints())]);
            if (!$wasCriminal && $user->isCriminal()) {
                $lines[] = t('  🚨 {player} ist jetzt kriminell.', ['{player}' => $user->getUsername()]);
            } elseif ($wasCriminal && !$user->isCriminal()) {
                $lines[] = t('  {player} ist nicht mehr kriminell.', ['{player}' => $user->getUsername()]);
            }
        }

        return [] === $lines ? [] : ['', t('Heldenpunkte (1 je {deaths} Tote; Kampf gegen Piraten und Kriminelle bringt welche, gegen alle anderen kostet er – höchstens {max} pro Kampf; ohne neue Untaten kommen {recovery} pro Tag zurück):', ['{deaths}' => self::DEATHS_PER_POINT, '{max}' => new Num(self::MAX_CRIME_PER_BATTLE), '{recovery}' => new Num(self::RECOVERY_PER_DAY)]), ...$lines];
    }

    /** Why the attacker may not attack the victim, or null. */
    public static function attackRefusal(User $attacker, User $victim, bool $atWar): ?TranslatableInterface
    {
        if ($attacker->isCriminal() || $atWar || $victim->isSystem() || $victim->isCriminal()
            || !\in_array($attacker->getLifeForm(), [LifeForm::Trader, LifeForm::Guardian], true)
            || !\in_array($victim->getLifeForm(), [LifeForm::Settler, LifeForm::Trader], true)) {
            return null;
        }

        return t('Als {lifeForm} greifst du keine Siedler und Händler an, die nicht kriminell sind – außer im Krieg. {player} ist {victimLifeForm}.', ['{lifeForm}' => t($attacker->getLifeForm()->label()), '{player}' => $victim->getUsername(), '{victimLifeForm}' => t($victim->getLifeForm()->label())]);
    }
}
