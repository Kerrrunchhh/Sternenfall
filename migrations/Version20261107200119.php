<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107200119 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Home planets (cannot be invaded)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet ADD homeworld TINYINT DEFAULT 0 NOT NULL');
        // Existing players: their oldest planet that was not taken by invasion is the home planet.
        $this->addSql('UPDATE planet p JOIN (SELECT owner_id, MIN(id) id FROM planet WHERE integrated_at IS NULL GROUP BY owner_id) h ON h.id = p.id SET p.homeworld = 1');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet DROP homeworld');
    }
}
