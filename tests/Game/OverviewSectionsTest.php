<?php

namespace App\Tests\Game;

use App\Entity\User;
use App\Game\OverviewSection;
use PHPUnit\Framework\TestCase;

/** Players choose which boxes the overview shows (Konto); everything shows until they choose. */
class OverviewSectionsTest extends TestCase
{
    public function testEverythingShowsByDefault(): void
    {
        $player = new User('neu', new \DateTimeImmutable());

        foreach (OverviewSection::cases() as $section) {
            self::assertTrue($player->showsOnOverview($section), $section->value);
        }
    }

    public function testOnlyTheChosenBoxesShow(): void
    {
        $player = new User('haendler', new \DateTimeImmutable());

        $player->showOnOverview([OverviewSection::Movements, OverviewSection::Hangar]);

        self::assertTrue($player->showsOnOverview(OverviewSection::Movements));
        self::assertTrue($player->showsOnOverview('hangar'), 'Twig asks with the string value');
        self::assertFalse($player->showsOnOverview(OverviewSection::TransportRequests));
        self::assertFalse($player->showsOnOverview('contacts'));
        self::assertTrue($player->showsOnOverview('some_future_box'), 'boxes added later show until hidden');

        $player->showOnOverview(OverviewSection::cases());
        self::assertTrue($player->showsOnOverview(OverviewSection::TransportRequests), 'shown again');
    }
}
