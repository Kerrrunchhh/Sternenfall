<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108092031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fleet intel from probes';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fleet_intel (id INT AUTO_INCREMENT NOT NULL, ships JSON NOT NULL, cargo DOUBLE PRECISION NOT NULL, scouted_at DATETIME NOT NULL, user_id INT NOT NULL, fleet_id INT NOT NULL, UNIQUE INDEX uniq_fleet_intel (user_id, fleet_id), INDEX IDX_3390CA56A76ED395 (user_id), INDEX IDX_3390CA564B061DF9 (fleet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fleet_intel ADD CONSTRAINT FK_3390CA56A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fleet_intel ADD CONSTRAINT FK_3390CA564B061DF9 FOREIGN KEY (fleet_id) REFERENCES fleet (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fleet_intel DROP FOREIGN KEY FK_3390CA56A76ED395');
        $this->addSql('ALTER TABLE fleet_intel DROP FOREIGN KEY FK_3390CA564B061DF9');
        $this->addSql('DROP TABLE fleet_intel');
    }
}
