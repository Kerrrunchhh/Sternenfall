<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109071825 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shareable battle reports';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE battle_report (id INT AUTO_INCREMENT NOT NULL, hash VARCHAR(32) NOT NULL, subject VARCHAR(150) NOT NULL, body LONGTEXT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_E1599E75D1B862B8 (hash), INDEX idx_battle_report_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE battle_report');
    }
}
