<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110082329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support tickets';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ticket (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(255) NOT NULL, subject VARCHAR(150) NOT NULL, body LONGTEXT NOT NULL, status VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, author_id INT DEFAULT NULL, reported_id INT DEFAULT NULL, INDEX idx_ticket_status (status, updated_at), INDEX IDX_97A0ADA3F675F31B (author_id), INDEX IDX_97A0ADA394BDEEB6 (reported_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE ticket_comment (id INT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, from_admin TINYINT NOT NULL, created_at DATETIME NOT NULL, ticket_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_98B80B3E700047D2 (ticket_id), INDEX IDX_98B80B3EF675F31B (author_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3F675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA394BDEEB6 FOREIGN KEY (reported_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ticket_comment ADD CONSTRAINT FK_98B80B3E700047D2 FOREIGN KEY (ticket_id) REFERENCES ticket (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ticket_comment ADD CONSTRAINT FK_98B80B3EF675F31B FOREIGN KEY (author_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket DROP FOREIGN KEY FK_97A0ADA3F675F31B');
        $this->addSql('ALTER TABLE ticket DROP FOREIGN KEY FK_97A0ADA394BDEEB6');
        $this->addSql('ALTER TABLE ticket_comment DROP FOREIGN KEY FK_98B80B3E700047D2');
        $this->addSql('ALTER TABLE ticket_comment DROP FOREIGN KEY FK_98B80B3EF675F31B');
        $this->addSql('DROP TABLE ticket');
        $this->addSql('DROP TABLE ticket_comment');
    }
}
