<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115072640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bronze, silver and golden arrows for the most combat experience (award)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE award (id INT AUTO_INCREMENT NOT NULL, period VARCHAR(255) NOT NULL, period_start DATETIME NOT NULL, experience DOUBLE PRECISION NOT NULL, awarded_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX award_period (period, period_start), INDEX IDX_8A5B2EE7A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE award ADD CONSTRAINT FK_8A5B2EE7A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE award DROP FOREIGN KEY FK_8A5B2EE7A76ED395');
        $this->addSql('DROP TABLE award');
    }
}
