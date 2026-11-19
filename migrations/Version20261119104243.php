<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Transport requests and contracts show the current planet name (planets renamed before this kept their old name there). */
final class Version20261119104243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transport requests and contracts: current planet names';
    }

    public function up(Schema $schema): void
    {
        foreach (['transport_request', 'contract'] as $table) {
            $this->addSql("UPDATE $table t JOIN planet p ON p.galaxy = t.galaxy AND p.solar_system = t.solar_system AND p.position = t.position SET t.planet_name = LEFT(p.name, 30)");
        }
    }

    public function down(Schema $schema): void
    {
        // The old names are not kept.
    }
}
