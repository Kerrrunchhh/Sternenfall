<?php

namespace App\Game;

use Random\Randomizer;

/**
 * Random names for new colonies: a syllable pair, sometimes with an epithet or number,
 * e.g. "Lyrandor", "Kaeris Minor" or "Velox III". Names the player already uses are avoided.
 */
final class PlanetNames
{
    private const array STARTS = [
        'Aster', 'Bor', 'Cael', 'Dra', 'Eri', 'Fen', 'Gal', 'Hel', 'Ix', 'Jor', 'Kae', 'Lyr', 'Mir', 'Nor',
        'Ori', 'Pyr', 'Quel', 'Ryn', 'Sol', 'Tal', 'Ul', 'Vel', 'Xan', 'Zeph', 'Aur', 'Bel', 'Cor', 'Del',
        'Esk', 'Gor', 'Ka', 'Lun', 'Mor', 'Nym', 'Sar', 'Ther', 'Vor', 'Yl',
    ];
    private const array ENDS = [
        'ion', 'ara', 'on', 'ea', 'is', 'ania', 'ox', 'heim', 'os', 'eth', 'ia', 'ur', 'ane', 'ix', 'yra',
        'andor', 'eon', 'ika', 'um', 'atis', 'orn', 'essa', 'ith', 'adon',
    ];
    private const array EPITHETS = ['Minor', 'Major', 'Prime', 'II', 'III', 'IV', 'V', 'Nova', 'Alpha', 'Beta'];

    /** @param list<string> $taken names to avoid (compared case-insensitively) */
    public static function random(array $taken = [], ?Randomizer $random = null): string
    {
        $random ??= new Randomizer();
        $used = array_map('mb_strtolower', $taken);
        $name = '';
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $name = self::STARTS[$random->getInt(0, \count(self::STARTS) - 1)].self::ENDS[$random->getInt(0, \count(self::ENDS) - 1)];
            if (0 === $random->getInt(0, 3)) {
                $name .= ' '.self::EPITHETS[$random->getInt(0, \count(self::EPITHETS) - 1)];
            }
            if (!\in_array(mb_strtolower($name), $used, true)) {
                return $name;
            }
        }

        return $name;
    }
}
