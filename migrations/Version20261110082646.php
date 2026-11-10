<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110082646 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Account deletion, ownerless planets, experience log';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE deleted_account (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(180) NOT NULL, deleted_at DATETIME NOT NULL, INDEX idx_deleted_account_at (deleted_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE experience_gain (id INT AUTO_INCREMENT NOT NULL, amount DOUBLE PRECISION NOT NULL, gained_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_experience_gain_at (gained_at), INDEX IDX_8485E21DA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE experience_gain ADD CONSTRAINT FK_8485E21DA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user ADD `system` TINYINT DEFAULT 0 NOT NULL, ADD delete_at DATETIME DEFAULT NULL, ADD delete_destroys_buildings TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE experience_gain DROP FOREIGN KEY FK_8485E21DA76ED395');
        $this->addSql('DROP TABLE deleted_account');
        $this->addSql('DROP TABLE experience_gain');
        $this->addSql('ALTER TABLE `user` DROP `system`, DROP delete_at, DROP delete_destroys_buildings');
    }
}
