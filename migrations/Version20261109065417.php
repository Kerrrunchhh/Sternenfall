<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109065417 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Player mail, quick contacts and last-seen time for the online status';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE contact (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, accepted_at DATETIME DEFAULT NULL, requester_id INT NOT NULL, addressee_id INT NOT NULL, UNIQUE INDEX uniq_contact_pair (requester_id, addressee_id), INDEX IDX_4C62E638ED442CF4 (requester_id), INDEX IDX_4C62E6382261B4C3 (addressee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE player_message (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(150) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, is_read TINYINT NOT NULL, deleted_by_sender TINYINT NOT NULL, deleted_by_recipient TINYINT NOT NULL, sender_id INT DEFAULT NULL, recipient_id INT DEFAULT NULL, INDEX idx_player_message_inbox (recipient_id, deleted_by_recipient, is_read), INDEX idx_player_message_sent (sender_id, deleted_by_sender), INDEX IDX_D749CF3AF624B39D (sender_id), INDEX IDX_D749CF3AE92F8F78 (recipient_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE contact ADD CONSTRAINT FK_4C62E638ED442CF4 FOREIGN KEY (requester_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE contact ADD CONSTRAINT FK_4C62E6382261B4C3 FOREIGN KEY (addressee_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE player_message ADD CONSTRAINT FK_D749CF3AF624B39D FOREIGN KEY (sender_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE player_message ADD CONSTRAINT FK_D749CF3AE92F8F78 FOREIGN KEY (recipient_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE user ADD last_seen_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE contact DROP FOREIGN KEY FK_4C62E638ED442CF4');
        $this->addSql('ALTER TABLE contact DROP FOREIGN KEY FK_4C62E6382261B4C3');
        $this->addSql('ALTER TABLE player_message DROP FOREIGN KEY FK_D749CF3AF624B39D');
        $this->addSql('ALTER TABLE player_message DROP FOREIGN KEY FK_D749CF3AE92F8F78');
        $this->addSql('DROP TABLE contact');
        $this->addSql('DROP TABLE player_message');
        $this->addSql('ALTER TABLE `user` DROP last_seen_at');
    }
}
