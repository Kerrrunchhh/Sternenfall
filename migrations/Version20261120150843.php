<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** No more debris fields: salvage ships take the wreckage right after a battle. */
final class Version20261120150843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove debris fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE debris_field');
        $this->addSql('ALTER TABLE planet_intel DROP debris_iron, DROP debris_titanium, DROP debris_seen_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE debris_field (id INT AUTO_INCREMENT NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, iron DOUBLE PRECISION NOT NULL, titanium DOUBLE PRECISION NOT NULL, UNIQUE INDEX uniq_debris_slot (galaxy, solar_system, position), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_0900_ai_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE planet_intel ADD debris_iron DOUBLE PRECISION DEFAULT NULL, ADD debris_titanium DOUBLE PRECISION DEFAULT NULL, ADD debris_seen_at DATETIME DEFAULT NULL');
    }
}
