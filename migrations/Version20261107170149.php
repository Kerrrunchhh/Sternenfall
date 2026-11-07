<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107170149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Population, bio cell and bunker';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet ADD population DOUBLE PRECISION NOT NULL');
        // Existing planets start like new ones: bio cell level 1 with 100 inhabitants.
        $this->addSql("UPDATE planet SET population = 100, buildings = JSON_SET(buildings, '$.bio_cell', 1) WHERE NOT JSON_CONTAINS_PATH(buildings, 'one', '$.bio_cell')");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql("UPDATE planet SET buildings = JSON_REMOVE(buildings, '$.bio_cell', '$.bunker')");
        $this->addSql("UPDATE planet SET construction_type = NULL, construction_target_level = NULL, construction_finishes_at = NULL WHERE construction_type IN ('bio_cell', 'bunker')");
        $this->addSql('ALTER TABLE planet DROP population');
    }
}
