<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110202100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE login_record (id INT AUTO_INCREMENT NOT NULL, ip VARCHAR(45) NOT NULL, user_agent VARCHAR(255) NOT NULL, fingerprint VARCHAR(64) DEFAULT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX idx_login_record_ip (ip), INDEX idx_login_record_fingerprint (fingerprint), INDEX idx_login_record_at (created_at), INDEX IDX_6C50B80BA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE resource_gift (id INT AUTO_INCREMENT NOT NULL, amount DOUBLE PRECISION NOT NULL, created_at DATETIME NOT NULL, sender_id INT NOT NULL, receiver_id INT NOT NULL, INDEX idx_resource_gift_sender (sender_id, created_at), INDEX IDX_AB5C39B7F624B39D (sender_id), INDEX IDX_AB5C39B7CD53EDB6 (receiver_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE login_record ADD CONSTRAINT FK_6C50B80BA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE resource_gift ADD CONSTRAINT FK_AB5C39B7F624B39D FOREIGN KEY (sender_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE resource_gift ADD CONSTRAINT FK_AB5C39B7CD53EDB6 FOREIGN KEY (receiver_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE login_record DROP FOREIGN KEY FK_6C50B80BA76ED395');
        $this->addSql('ALTER TABLE resource_gift DROP FOREIGN KEY FK_AB5C39B7F624B39D');
        $this->addSql('ALTER TABLE resource_gift DROP FOREIGN KEY FK_AB5C39B7CD53EDB6');
        $this->addSql('DROP TABLE login_record');
        $this->addSql('DROP TABLE resource_gift');
    }
}
