<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261117143816 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tickers: admins, muting members, a deputy owner';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticker ADD deputy_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticker ADD CONSTRAINT FK_7EC308964B6F93BB FOREIGN KEY (deputy_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7EC308964B6F93BB ON ticker (deputy_id)');
        $this->addSql('ALTER TABLE ticker_member ADD `admin` TINYINT DEFAULT 0 NOT NULL, ADD muted_until DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticker DROP FOREIGN KEY FK_7EC308964B6F93BB');
        $this->addSql('DROP INDEX IDX_7EC308964B6F93BB ON ticker');
        $this->addSql('ALTER TABLE ticker DROP deputy_id');
        $this->addSql('ALTER TABLE ticker_member DROP `admin`, DROP muted_until');
    }
}
