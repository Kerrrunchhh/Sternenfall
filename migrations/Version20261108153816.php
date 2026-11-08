<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108153816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fleets remember the own planet they were sent to a trade post from';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fleet ADD home_galaxy SMALLINT DEFAULT NULL, ADD home_system SMALLINT DEFAULT NULL, ADD home_position SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fleet DROP home_galaxy, DROP home_system, DROP home_position');
    }
}
