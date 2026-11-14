<?php

namespace App\Game;

use App\Entity\User;
use App\I18n\Num;

use function Symfony\Component\Translation\t;

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
     * @return array<'attack'|'shield'|'speed', array{base: int, value: int, notes: list<\Symfony\Contracts\Translation\TranslatableInterface>}>
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
        if (1.0 !== $player->speedFactor()) {
            $notes[] = t('Lebensform {lifeForm}: Tempo ×{factor}', ['{lifeForm}' => t($lifeForm->label()), '{factor}' => new Num($player->speedFactor(), 1)]);
        } elseif (1.0 !== $lifeForm->speedFactor()) {
            $notes[] = t('Kriminell: kein Tempovorteil der Lebensform {lifeForm}', ['{lifeForm}' => t($lifeForm->label())]);
        }
        $speed = $this->economy->shipSpeed($type, $player);
        $researched = (int) round($spec->speed * self::factor($spec->drive, $player) * $player->speedFactor());
        if ($speed > $researched) {
            $notes[] = ShipType::Scout === $type
                ? t('Immer 10 % schneller als der Fusionator')
                : t('Immer 10 % schneller als das schnellste reguläre Schiff');
        }
        $stats['speed'] = ['base' => $spec->speed, 'value' => $speed, 'notes' => $notes];

        return $stats;
    }

    private static function factor(?ResearchType $research, User $player): float
    {
        return 1 + Battle::RESEARCH_BONUS * (null === $research ? 0 : $player->getResearchLevel($research));
    }

    /** @return list<\Symfony\Contracts\Translation\TranslatableInterface> */
    private static function researchNotes(?ResearchType $research, User $player, string $what): array
    {
        if (null === $research) {
            return [];
        }
        $level = $player->getResearchLevel($research);

        return [t('{research} Stufe {level}: {what} +{bonus} %', ['{research}' => t($research->label()), '{level}' => $level, '{what}' => t($what), '{bonus}' => (int) round(100 * Battle::RESEARCH_BONUS * $level)])];
    }
}
