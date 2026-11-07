<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107164921 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Resources: iron, titanium, food, water, hydrogen';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Keep existing stock: metal -> iron, crystal -> titanium, fuel -> hydrogen.
        $this->addSql('ALTER TABLE planet RENAME COLUMN metal TO iron, RENAME COLUMN crystal TO titanium, RENAME COLUMN fuel TO hydrogen, ADD food DOUBLE PRECISION NOT NULL, ADD water DOUBLE PRECISION NOT NULL');
        $this->addSql('UPDATE planet SET food = 200, water = 200');
        // Renamed production buildings (levels and running constructions).
        foreach (['metal_mine' => 'iron_mine', 'crystal_mine' => 'titanium_mine', 'fuel_refinery' => 'electrolysis'] as $old => $new) {
            $this->addSql("UPDATE planet SET buildings = JSON_REMOVE(JSON_SET(buildings, '$.$new', JSON_EXTRACT(buildings, '$.$old')), '$.$old') WHERE JSON_CONTAINS_PATH(buildings, 'one', '$.$old')");
            $this->addSql("UPDATE planet SET construction_type = '$new' WHERE construction_type = '$old'");
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet RENAME COLUMN iron TO metal, RENAME COLUMN titanium TO crystal, RENAME COLUMN hydrogen TO fuel, DROP food, DROP water');
    }
}
