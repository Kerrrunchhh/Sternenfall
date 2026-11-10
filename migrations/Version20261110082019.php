<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110082019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vacation mode';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD vacation_since DATETIME DEFAULT NULL, ADD vacation_until DATETIME DEFAULT NULL, ADD vacation_forced TINYINT DEFAULT 0 NOT NULL, ADD vacation_reason VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` DROP vacation_since, DROP vacation_until, DROP vacation_forced, DROP vacation_reason');
    }
}
