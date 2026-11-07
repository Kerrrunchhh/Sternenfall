<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107185322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Founder election votes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alliance_vote (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, alliance_id INT NOT NULL, voter_id INT NOT NULL, candidate_id INT NOT NULL, UNIQUE INDEX uniq_alliance_vote (alliance_id, voter_id), INDEX IDX_2B7CA20810A0EA3F (alliance_id), INDEX IDX_2B7CA208EBB4B8AD (voter_id), INDEX IDX_2B7CA20891BD8781 (candidate_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE alliance_vote ADD CONSTRAINT FK_2B7CA20810A0EA3F FOREIGN KEY (alliance_id) REFERENCES alliance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alliance_vote ADD CONSTRAINT FK_2B7CA208EBB4B8AD FOREIGN KEY (voter_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE alliance_vote ADD CONSTRAINT FK_2B7CA20891BD8781 FOREIGN KEY (candidate_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alliance_vote DROP FOREIGN KEY FK_2B7CA20810A0EA3F');
        $this->addSql('ALTER TABLE alliance_vote DROP FOREIGN KEY FK_2B7CA208EBB4B8AD');
        $this->addSql('ALTER TABLE alliance_vote DROP FOREIGN KEY FK_2B7CA20891BD8781');
        $this->addSql('DROP TABLE alliance_vote');
    }
}
