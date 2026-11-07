<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107195300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Player profiles, alliance pictures and internal/external texts';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alliance ADD internal_text LONGTEXT NOT NULL, ADD image VARCHAR(40) DEFAULT NULL, CHANGE description external_text LONGTEXT NOT NULL');
        $this->addSql('ALTER TABLE `user` ADD avatar VARCHAR(40) DEFAULT NULL, ADD profile_text LONGTEXT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alliance CHANGE external_text description LONGTEXT NOT NULL, DROP internal_text, DROP image');
        $this->addSql('ALTER TABLE `user` DROP avatar, DROP profile_text');
    }
}
