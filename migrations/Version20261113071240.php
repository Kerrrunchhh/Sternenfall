<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113071240 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Transport requests remember when they were closed; finished ones are deleted three days later';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transport_request ADD closed_at DATETIME DEFAULT NULL');
        // Requests finished before: their three days start now.
        $this->addSql("UPDATE transport_request SET closed_at = UTC_TIMESTAMP() WHERE status IN ('done', 'cancelled')");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE transport_request DROP closed_at');
    }
}
