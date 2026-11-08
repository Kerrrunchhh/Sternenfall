<?php

namespace App\Tests\Game;

use App\Entity\Planet;
use App\Entity\User;
use App\Game\Economy;
use App\Game\LifeForm;
use App\Game\PlanetUpdater;
use App\Game\Resources;
use App\Game\ShipType;
use App\Game\BuildingType;
use PHPUnit\Framework\TestCase;

class LifeFormTest extends TestCase
{
    private Economy $economy;
    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        $this->economy = new Economy(1.0);
        $this->start = new \DateTimeImmutable('2026-01-01 12:00:00');
    }

    public function testEverybodyStartsAsSettlerAndMayChangeAfterThirtyDays(): void
    {
        $player = new User('neu', $this->start);

        self::assertSame(LifeForm::Settler, $player->getLifeForm());
        self::assertEquals($this->start->modify('+30 days'), $player->getNextLifeFormChangeAt());

        $player->changeLifeForm(LifeForm::Pirate, $this->start->modify('+31 days'));
        self::assertSame(LifeForm::Pirate, $player->getLifeForm());
        self::assertEquals($this->start->modify('+61 days'), $player->getNextLifeFormChangeAt());
    }

    public function testShipSpeedPerLifeForm(): void
    {
        $speeds = [];
        foreach (LifeForm::cases() as $form) {
            $player = $this->player($form);
            $speeds[$form->value] = $this->economy->shipSpeed(ShipType::Pfeil, $player);
        }

        self::assertSame(12000, $speeds['settler']);
        self::assertSame(12000, $speeds['trader']);
        self::assertSame(18000, $speeds['pirate']);
        self::assertSame(24000, $speeds['guardian']);
    }

    public function testShipCostsPerLifeForm(): void
    {
        // Pfeil: 3000 iron, 1000 titanium → 200 credits base
        $settler = $this->player(LifeForm::Settler);
        $guardian = $this->player(LifeForm::Guardian);
        $trader = $this->player(LifeForm::Trader);

        self::assertSame(0.0, $this->economy->shipCredits(ShipType::Pfeil, $settler));
        self::assertEquals(new Resources(iron: 3000, titanium: 1000), $this->economy->shipCost(ShipType::Pfeil, $settler));

        self::assertSame(200.0, $this->economy->shipCredits(ShipType::Pfeil, $guardian));

        self::assertSame(300.0, $this->economy->shipCredits(ShipType::Pfeil, $trader));
        self::assertEquals(new Resources(iron: 4500, titanium: 1500), $this->economy->shipCost(ShipType::Pfeil, $trader));
    }

    public function testOnlyTradersMayBuildFusionators(): void
    {
        foreach (LifeForm::cases() as $form) {
            $planet = new Planet($this->player($form), 'Werft', 1, 1, 1, $this->start);
            $missing = (new Economy(1.0))->missingShipRequirements(ShipType::Fusionator, $planet);
            $traderRule = 'Nur Händler können Fusionator bauen';

            if (LifeForm::Trader === $form) {
                self::assertNotContains($traderRule, $missing);
            } else {
                self::assertSame($traderRule, $missing[0], $form->label());
            }
        }
        // Other ships are not restricted by life form.
        $settlerPlanet = new Planet($this->player(LifeForm::Settler), 'Werft', 1, 1, 1, $this->start);
        self::assertNotContains('Nur Händler können Pfeil bauen', $this->economy->missingShipRequirements(ShipType::Pfeil, $settlerPlanet));
    }

    public function testTradersProduceTwiceAsMuch(): void
    {
        // Same slot type for both, so only the life form makes the difference.
        $slot = PlanetUpdaterTest::slotOf(\App\Game\PlanetType::Blue);
        $settler = new Planet($this->player(LifeForm::Settler), 'A', 1, 1, $slot, $this->start);
        $trader = new Planet($this->player(LifeForm::Trader), 'B', 1, 1, $slot, $this->start);
        foreach ([$settler, $trader] as $planet) {
            $planet->setBuildingLevel(BuildingType::IronMine, 3)->setBuildingLevel(BuildingType::Farm, 2);
        }

        $a = $this->economy->productionPerHour($settler);
        $b = $this->economy->productionPerHour($trader);
        self::assertEqualsWithDelta(2 * $a->iron, $b->iron, 0.001);
        self::assertEqualsWithDelta(2 * $a->food, $b->food, 0.001);
    }

    public function testPopulationEarnsCredits(): void
    {
        $player = $this->player(LifeForm::Settler);
        $planet = new Planet($player, 'A', 1, 1, 1, $this->start);
        $planet->setBuildingLevel(BuildingType::BioCell, 1)->setPopulation(250);
        $planet->setResources(new Resources(food: 10_000, water: 10_000));

        (new PlanetUpdater($this->economy))->update($planet, $this->start->modify('+2 hours'));

        // 250 inhabitants × 1 credit per hour × 2 hours
        self::assertEqualsWithDelta(500, $player->getCredits(), 0.001);
    }

    private function player(LifeForm $form): User
    {
        $player = new User($form->value, $this->start);
        $player->changeLifeForm($form, $this->start);

        return $player;
    }
}
