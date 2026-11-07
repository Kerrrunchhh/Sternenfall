<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107164114 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE planet (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(30) NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, metal DOUBLE PRECISION NOT NULL, crystal DOUBLE PRECISION NOT NULL, fuel DOUBLE PRECISION NOT NULL, resources_updated_at DATETIME NOT NULL, buildings JSON NOT NULL, construction_type VARCHAR(255) DEFAULT NULL, construction_target_level INT DEFAULT NULL, construction_finishes_at DATETIME DEFAULT NULL, owner_id INT NOT NULL, UNIQUE INDEX uniq_planet_coordinates (galaxy, solar_system, position), INDEX IDX_68136AA57E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(30) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649F85E0677 (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE planet ADD CONSTRAINT FK_68136AA57E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet DROP FOREIGN KEY FK_68136AA57E3C61F9');
        $this->addSql('DROP TABLE planet');
        $this->addSql('DROP TABLE `user`');
    }
}
