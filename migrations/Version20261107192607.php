<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107192607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Debris fields';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE debris_field (id INT AUTO_INCREMENT NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, iron DOUBLE PRECISION NOT NULL, titanium DOUBLE PRECISION NOT NULL, UNIQUE INDEX uniq_debris_slot (galaxy, solar_system, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE debris_field');
    }
}
