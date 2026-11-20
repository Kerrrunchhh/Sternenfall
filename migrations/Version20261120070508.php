<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Plunder order of attacks and raids: per fleet, and the last one used per player. */
final class Version20261120070508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Plunder order for attacks and raids';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fleet ADD loot_order JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD loot_order JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fleet DROP loot_order');
        $this->addSql('ALTER TABLE `user` DROP loot_order');
    }
}
