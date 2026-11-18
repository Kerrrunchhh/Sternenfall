<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261118110005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Player activity log for admins (30 days)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE action_log_entry (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(20) NOT NULL, text LONGTEXT NOT NULL, created_at DATETIME NOT NULL, actor_id INT NOT NULL, other_id INT DEFAULT NULL, INDEX idx_action_log_actor (actor_id, created_at), INDEX idx_action_log_other (other_id, created_at), INDEX idx_action_log_created (created_at), INDEX IDX_AF59653010DAF24A (actor_id), INDEX IDX_AF596530998D9879 (other_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE action_log_entry ADD CONSTRAINT FK_AF59653010DAF24A FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE action_log_entry ADD CONSTRAINT FK_AF596530998D9879 FOREIGN KEY (other_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE action_log_entry DROP FOREIGN KEY FK_AF59653010DAF24A');
        $this->addSql('ALTER TABLE action_log_entry DROP FOREIGN KEY FK_AF596530998D9879');
        $this->addSql('DROP TABLE action_log_entry');
    }
}
