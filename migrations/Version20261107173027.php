<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107173027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fleets and messages';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fleet (id INT AUTO_INCREMENT NOT NULL, mission VARCHAR(255) NOT NULL, target_galaxy SMALLINT NOT NULL, target_system SMALLINT NOT NULL, target_position SMALLINT NOT NULL, ships JSON NOT NULL, cargo JSON NOT NULL, depart_at DATETIME NOT NULL, arrive_at DATETIME NOT NULL, return_at DATETIME NOT NULL, returning TINYINT NOT NULL, owner_id INT NOT NULL, origin_id INT NOT NULL, INDEX idx_fleet_arrive (returning, arrive_at), INDEX idx_fleet_return (returning, return_at), INDEX IDX_A05E1E477E3C61F9 (owner_id), INDEX IDX_A05E1E4756A273CC (origin_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE message (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(150) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, is_read TINYINT NOT NULL, recipient_id INT NOT NULL, INDEX idx_message_inbox (recipient_id, is_read), INDEX IDX_B6BD307FE92F8F78 (recipient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fleet ADD CONSTRAINT FK_A05E1E477E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fleet ADD CONSTRAINT FK_A05E1E4756A273CC FOREIGN KEY (origin_id) REFERENCES planet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE message ADD CONSTRAINT FK_B6BD307FE92F8F78 FOREIGN KEY (recipient_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fleet DROP FOREIGN KEY FK_A05E1E477E3C61F9');
        $this->addSql('ALTER TABLE fleet DROP FOREIGN KEY FK_A05E1E4756A273CC');
        $this->addSql('ALTER TABLE message DROP FOREIGN KEY FK_B6BD307FE92F8F78');
        $this->addSql('DROP TABLE fleet');
        $this->addSql('DROP TABLE message');
    }
}
