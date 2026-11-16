<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116191229 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Polls for all players, in German and English';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE poll (id INT AUTO_INCREMENT NOT NULL, question VARCHAR(500) NOT NULL, options JSON NOT NULL, question_en VARCHAR(500) DEFAULT NULL, options_en JSON DEFAULT NULL, multiple TINYINT NOT NULL, created_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_84BCFA45B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE poll_vote (id INT AUTO_INCREMENT NOT NULL, choices JSON NOT NULL, reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, poll_id INT NOT NULL, user_id INT DEFAULT NULL, UNIQUE INDEX uniq_poll_vote (poll_id, user_id), INDEX IDX_ED568EBE3C947C0F (poll_id), INDEX IDX_ED568EBEA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE poll ADD CONSTRAINT FK_84BCFA45B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE poll_vote ADD CONSTRAINT FK_ED568EBE3C947C0F FOREIGN KEY (poll_id) REFERENCES poll (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE poll_vote ADD CONSTRAINT FK_ED568EBEA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE poll DROP FOREIGN KEY FK_84BCFA45B03A8386');
        $this->addSql('ALTER TABLE poll_vote DROP FOREIGN KEY FK_ED568EBE3C947C0F');
        $this->addSql('ALTER TABLE poll_vote DROP FOREIGN KEY FK_ED568EBEA76ED395');
        $this->addSql('DROP TABLE poll');
        $this->addSql('DROP TABLE poll_vote');
    }
}
