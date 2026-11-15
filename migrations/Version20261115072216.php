<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261115072216 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Authors can edit their posts on the black board and in tickers (edited_at)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alliance_post ADD edited_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ticker_post ADD edited_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE alliance_post DROP edited_at');
        $this->addSql('ALTER TABLE ticker_post DROP edited_at');
    }
}
