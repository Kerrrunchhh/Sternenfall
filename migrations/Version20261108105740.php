<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108105740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planet a running research belongs to (ends when that planet is invaded); shield generator needs no schema change';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD research_planet_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D649D91248D9 FOREIGN KEY (research_planet_id) REFERENCES planet (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649D91248D9 ON user (research_planet_id)');
        // Research already running belongs to the player's home planet.
        $this->addSql('UPDATE `user` u SET u.research_planet_id = (SELECT p.id FROM planet p WHERE p.owner_id = u.id ORDER BY p.homeworld DESC, p.id ASC LIMIT 1) WHERE u.research_type IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649D91248D9');
        $this->addSql('DROP INDEX IDX_8D93D649D91248D9 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP research_planet_id');
    }
}
