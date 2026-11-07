<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107181444 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Building queue and sortable shipyard queue';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE building_order (id INT AUTO_INCREMENT NOT NULL, building_type VARCHAR(255) NOT NULL, target_level INT NOT NULL, cost JSON NOT NULL, position INT NOT NULL, seconds INT DEFAULT NULL, started_at DATETIME DEFAULT NULL, progress_seconds INT DEFAULT 0 NOT NULL, planet_id INT NOT NULL, INDEX IDX_99892202A25E9820 (planet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE building_order ADD CONSTRAINT FK_99892202A25E9820 FOREIGN KEY (planet_id) REFERENCES planet (id) ON DELETE CASCADE');
        // Running constructions become the first entry of the new building queue (cost unknown: no refund).
        $this->addSql("INSERT INTO building_order (planet_id, building_type, target_level, cost, position, seconds, started_at, progress_seconds)
            SELECT id, construction_type, construction_target_level, '{}', 0, GREATEST(1, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), construction_finishes_at)), UTC_TIMESTAMP(), 0
            FROM planet WHERE construction_type IS NOT NULL");
        $this->addSql('ALTER TABLE planet DROP construction_type, DROP construction_target_level, DROP construction_finishes_at');
        $this->addSql('ALTER TABLE shipyard_order ADD position INT DEFAULT 0 NOT NULL, ADD progress_seconds INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE shipyard_order SET position = id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE building_order DROP FOREIGN KEY FK_99892202A25E9820');
        $this->addSql('DROP TABLE building_order');
        $this->addSql('ALTER TABLE planet ADD construction_type VARCHAR(255) DEFAULT NULL, ADD construction_target_level INT DEFAULT NULL, ADD construction_finishes_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE shipyard_order DROP position, DROP progress_seconds');
    }
}
