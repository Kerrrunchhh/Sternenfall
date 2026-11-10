<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109085139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Diplomacy: relations and proposals between alliances';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE diplomacy_proposal (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(255) NOT NULL, ending TINYINT NOT NULL, stage VARCHAR(10) NOT NULL, own_votes JSON NOT NULL, other_votes JSON NOT NULL, created_at DATETIME NOT NULL, proposer_id INT NOT NULL, target_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX IDX_D5A16699B13FA634 (proposer_id), INDEX IDX_D5A16699158E0B66 (target_id), INDEX IDX_D5A16699B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE diplomatic_relation (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(255) NOT NULL, since DATETIME NOT NULL, first_id INT NOT NULL, second_id INT NOT NULL, INDEX IDX_DF42DF25E84D625F (first_id), INDEX IDX_DF42DF25FF961BCC (second_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE diplomacy_proposal ADD CONSTRAINT FK_D5A16699B13FA634 FOREIGN KEY (proposer_id) REFERENCES alliance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE diplomacy_proposal ADD CONSTRAINT FK_D5A16699158E0B66 FOREIGN KEY (target_id) REFERENCES alliance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE diplomacy_proposal ADD CONSTRAINT FK_D5A16699B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE diplomatic_relation ADD CONSTRAINT FK_DF42DF25E84D625F FOREIGN KEY (first_id) REFERENCES alliance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE diplomatic_relation ADD CONSTRAINT FK_DF42DF25FF961BCC FOREIGN KEY (second_id) REFERENCES alliance (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE diplomacy_proposal DROP FOREIGN KEY FK_D5A16699B13FA634');
        $this->addSql('ALTER TABLE diplomacy_proposal DROP FOREIGN KEY FK_D5A16699158E0B66');
        $this->addSql('ALTER TABLE diplomacy_proposal DROP FOREIGN KEY FK_D5A16699B03A8386');
        $this->addSql('ALTER TABLE diplomatic_relation DROP FOREIGN KEY FK_DF42DF25E84D625F');
        $this->addSql('ALTER TABLE diplomatic_relation DROP FOREIGN KEY FK_DF42DF25FF961BCC');
        $this->addSql('DROP TABLE diplomacy_proposal');
        $this->addSql('DROP TABLE diplomatic_relation');
    }
}
