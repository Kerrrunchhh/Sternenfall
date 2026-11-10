<?php

namespace App\Game;

use App\Entity\User;

/**
 * A unit's values as the player's research shapes them: attack (weapon research),
 * shield (shield research) and speed (drive research, life form, fixed speed order).
 * Battles and the shipyard use the same numbers.
 */
final readonly class ShipStats
{
    public function __construct(private Economy $economy)
    {
    }

    public static function attack(ShipType $type, User $player): float
    {
        return $type->spec()->attack * self::factor($type->weaponResearch(), $player);
    }

    public static function shield(ShipType $type, User $player): float
    {
        return $type->spec()->shield * self::factor($type->shieldResearch(), $player);
    }

    /**
     * Base and researched value of every value research changes, with what changes it.
     *
     * @return array<'attack'|'shield'|'speed', array{base: int, value: int, notes: list<string>}>
     */
    public function describe(ShipType $type, User $player): array
    {
        $spec = $type->spec();
        $stats = [
            'attack' => ['base' => $spec->attack, 'value' => (int) round(self::attack($type, $player)), 'notes' => self::researchNotes($type->weaponResearch(), $player, 'Angriff')],
            'shield' => ['base' => $spec->shield, 'value' => (int) round(self::shield($type, $player)), 'notes' => self::researchNotes($type->shieldResearch(), $player, 'Schild')],
        ];
        if ($spec->attack <= 0) {
            $stats['attack']['notes'] = [];
        }
        if ($spec->shield <= 0) {
            $stats['shield']['notes'] = [];
        }
        if ($type->isStationary()) {
            return $stats;
        }

        $notes = self::researchNotes($spec->drive, $player, 'Tempo');
        $lifeForm = $player->getLifeForm();
        if (1.0 !== $lifeForm->speedFactor()) {
            $notes[] = \sprintf('Lebensform %s: Tempo ×%s', $lifeForm->label(), str_replace('.', ',', (string) $lifeForm->speedFactor()));
        }
        $speed = $this->economy->shipSpeed($type, $player);
        $researched = (int) round($spec->speed * self::factor($spec->drive, $player) * $lifeForm->speedFactor());
        if ($speed > $researched) {
            $notes[] = ShipType::Scout === $type
                ? 'Immer 10 % schneller als der Fusionator'
                : 'Immer 10 % schneller als das schnellste reguläre Schiff';
        }
        $stats['speed'] = ['base' => $spec->speed, 'value' => $speed, 'notes' => $notes];

        return $stats;
    }

    private static function factor(?ResearchType $research, User $player): float
    {
        return 1 + Battle::RESEARCH_BONUS * (null === $research ? 0 : $player->getResearchLevel($research));
    }

    /** @return list<string> */
    private static function researchNotes(?ResearchType $research, User $player, string $what): array
    {
        if (null === $research) {
            return [];
        }
        $level = $player->getResearchLevel($research);

        return [\sprintf('%s Stufe %d: %s +%d %%', $research->label(), $level, $what, (int) round(100 * Battle::RESEARCH_BONUS * $level))];
    }
}
