<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107171642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Research and shipyard';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE shipyard_order (id INT AUTO_INCREMENT NOT NULL, ship_type VARCHAR(255) NOT NULL, quantity INT NOT NULL, remaining INT NOT NULL, unit_seconds INT NOT NULL, started_at DATETIME DEFAULT NULL, planet_id INT NOT NULL, INDEX IDX_4687F909A25E9820 (planet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE shipyard_order ADD CONSTRAINT FK_4687F909A25E9820 FOREIGN KEY (planet_id) REFERENCES planet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE planet ADD ships JSON DEFAULT NULL');
        $this->addSql("UPDATE planet SET ships = '{}'");
        $this->addSql('ALTER TABLE planet MODIFY ships JSON NOT NULL');
        $this->addSql('ALTER TABLE `user` ADD research JSON DEFAULT NULL, ADD research_type VARCHAR(255) DEFAULT NULL, ADD research_target_level INT DEFAULT NULL, ADD research_finishes_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE `user` SET research = '{}'");
        $this->addSql('ALTER TABLE `user` MODIFY research JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shipyard_order DROP FOREIGN KEY FK_4687F909A25E9820');
        $this->addSql('DROP TABLE shipyard_order');
        $this->addSql('ALTER TABLE planet DROP ships');
        $this->addSql('ALTER TABLE `user` DROP research, DROP research_type, DROP research_target_level, DROP research_finishes_at');
    }
}
