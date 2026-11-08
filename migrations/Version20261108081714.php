<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108081714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planet intel (scouted planet types)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE planet_intel (id INT AUTO_INCREMENT NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, type VARCHAR(255) NOT NULL, scouted_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_planet_intel (user_id, galaxy, solar_system, position), INDEX IDX_928C71C8A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE planet_intel ADD CONSTRAINT FK_928C71C8A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet_intel DROP FOREIGN KEY FK_928C71C8A76ED395');
        $this->addSql('DROP TABLE planet_intel');
    }
}
