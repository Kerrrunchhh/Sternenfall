<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116130235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mails sent per hour';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE mail_stat (hour DATETIME NOT NULL, mails INT DEFAULT 0 NOT NULL, recipients INT DEFAULT 0 NOT NULL, failed INT DEFAULT 0 NOT NULL, PRIMARY KEY (hour)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE mail_stat');
    }
}
