<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107184803 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alliances, black board and requests';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alliance (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(40) NOT NULL, tag VARCHAR(6) NOT NULL, life_form VARCHAR(255) NOT NULL, description LONGTEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_6CBA583F5E237E06 (name), UNIQUE INDEX UNIQ_6CBA583F389B783 (tag), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE alliance_post (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, alliance_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX idx_alliance_post (alliance_id, created_at), INDEX IDX_2BE64BE110A0EA3F (alliance_id), INDEX IDX_2BE64BE1F675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE alliance_request (id INT AUTO_INCREMENT NOT NULL, invitation TINYINT NOT NULL, created_at DATETIME NOT NULL, alliance_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_alliance_request (alliance_id, user_id), INDEX IDX_E49B22BC10A0EA3F (alliance_id), INDEX IDX_E49B22BCA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE alliance_post ADD CONSTRAINT FK_2BE64BE110A0EA3F FOREIGN KEY (alliance_id) REFERENCES alliance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alliance_post ADD CONSTRAINT FK_2BE64BE1F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE alliance_request ADD CONSTRAINT FK_E49B22BC10A0EA3F FOREIGN KEY (alliance_id) REFERENCES alliance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alliance_request ADD CONSTRAINT FK_E49B22BCA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user ADD alliance_rank VARCHAR(255) DEFAULT NULL, ADD alliance_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D64910A0EA3F FOREIGN KEY (alliance_id) REFERENCES alliance (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D64910A0EA3F ON user (alliance_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alliance_post DROP FOREIGN KEY FK_2BE64BE110A0EA3F');
        $this->addSql('ALTER TABLE alliance_post DROP FOREIGN KEY FK_2BE64BE1F675F31B');
        $this->addSql('ALTER TABLE alliance_request DROP FOREIGN KEY FK_E49B22BC10A0EA3F');
        $this->addSql('ALTER TABLE alliance_request DROP FOREIGN KEY FK_E49B22BCA76ED395');
        $this->addSql('DROP TABLE alliance');
        $this->addSql('DROP TABLE alliance_post');
        $this->addSql('DROP TABLE alliance_request');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D64910A0EA3F');
        $this->addSql('DROP INDEX IDX_8D93D64910A0EA3F ON `user`');
        $this->addSql('ALTER TABLE `user` DROP alliance_rank, DROP alliance_id');
    }
}
