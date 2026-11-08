<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108145717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remembered coordinates (bookmarks) of players';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE bookmark (id INT AUTO_INCREMENT NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, label VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, UNIQUE INDEX uniq_bookmark_slot (owner_id, galaxy, solar_system, position), INDEX IDX_DA62921D7E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE bookmark ADD CONSTRAINT FK_DA62921D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE bookmark DROP FOREIGN KEY FK_DA62921D7E3C61F9');
        $this->addSql('DROP TABLE bookmark');
    }
}
