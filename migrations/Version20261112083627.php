<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112083627 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'General attack bans; losses, annulment and refunds of combat reports';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE attack_ban (id INT AUTO_INCREMENT NOT NULL, lifted_at DATETIME DEFAULT NULL, reason VARCHAR(500) NOT NULL, started_at DATETIME NOT NULL, ends_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE battle_report ADD losses JSON DEFAULT NULL, ADD annulled_at DATETIME DEFAULT NULL, ADD annul_reason VARCHAR(500) DEFAULT NULL, ADD refunds JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE attack_ban');
        $this->addSql('ALTER TABLE battle_report DROP losses, DROP annulled_at, DROP annul_reason, DROP refunds');
    }
}
