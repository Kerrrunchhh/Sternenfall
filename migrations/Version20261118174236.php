<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118174236 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Credit statement per player (90 days)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE credit_booking (id INT AUTO_INCREMENT NOT NULL, amount DOUBLE PRECISION NOT NULL, kind VARCHAR(20) NOT NULL, counterparty_name VARCHAR(60) DEFAULT NULL, purpose LONGTEXT NOT NULL, goods JSON NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, counterparty_id INT DEFAULT NULL, trade_post_id INT DEFAULT NULL, INDEX idx_credit_booking_user (user_id, created_at), INDEX idx_credit_booking_post (trade_post_id, created_at), INDEX idx_credit_booking_created (created_at), INDEX IDX_DA888AF1A76ED395 (user_id), INDEX IDX_DA888AF1DB1FAD05 (counterparty_id), INDEX IDX_DA888AF1B8095BDA (trade_post_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE credit_booking ADD CONSTRAINT FK_DA888AF1A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE credit_booking ADD CONSTRAINT FK_DA888AF1DB1FAD05 FOREIGN KEY (counterparty_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE credit_booking ADD CONSTRAINT FK_DA888AF1B8095BDA FOREIGN KEY (trade_post_id) REFERENCES trade_post (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE credit_booking DROP FOREIGN KEY FK_DA888AF1A76ED395');
        $this->addSql('ALTER TABLE credit_booking DROP FOREIGN KEY FK_DA888AF1DB1FAD05');
        $this->addSql('ALTER TABLE credit_booking DROP FOREIGN KEY FK_DA888AF1B8095BDA');
        $this->addSql('DROP TABLE credit_booking');
    }
}
