<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Notes for the admins' mail digest (sign-ups, booked extended accounts). */
final class Version20261119111718 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin notices for the mail digest';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_notice (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(12) NOT NULL, username VARCHAR(64) NOT NULL, created_at DATETIME NOT NULL, sent_at DATETIME DEFAULT NULL, user_id INT DEFAULT NULL, INDEX idx_admin_notice_sent (sent_at), INDEX IDX_15C5743BA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE admin_notice ADD CONSTRAINT FK_15C5743BA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_notice DROP FOREIGN KEY FK_15C5743BA76ED395');
        $this->addSql('DROP TABLE admin_notice');
    }
}
