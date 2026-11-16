<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261116064421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Team messages in tickets can be edited';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket ADD body_edited_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_comment ADD edited_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket DROP body_edited_at');
        $this->addSql('ALTER TABLE ticket_comment DROP edited_at');
    }
}
