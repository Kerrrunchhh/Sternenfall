<?php

namespace App\Tests\Game;

use App\Twig\GameExtension;
use PHPUnit\Framework\TestCase;

/** Public combat reports (shared link, newspaper) must not reveal where the fight took place. */
final class HideCoordinatesTest extends TestCase
{
    public function testBracketedCoordinatesAreMasked(): void
    {
        self::assertSame(
            'Kampf bei Vorania [xx:xxx:x] – Flotte von [x:xx:xx] und [x:xxx:x-x]',
            GameExtension::hideCoordinates('Kampf bei Vorania [16:185:6] – Flotte von [3:12:15] und [3:196:1-3]'),
        );
    }

    public function testTimesAndOtherNumbersStay(): void
    {
        $text = "Runde 3 um 12:58:09: 1.250 Schaden, Index [7]\n";
        self::assertSame($text, GameExtension::hideCoordinates($text));
    }
}
