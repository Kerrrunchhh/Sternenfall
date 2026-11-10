<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109085346 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fleet stay limits (parked, stranded) and invaded planet slots';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fleet ADD parked_at DATETIME DEFAULT NULL, ADD stranded_since DATETIME DEFAULT NULL, ADD stranded_ships JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE planet ADD invaded_at DATETIME DEFAULT NULL, ADD invaded_in_war TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fleet DROP parked_at, DROP stranded_since, DROP stranded_ships');
        $this->addSql('ALTER TABLE planet DROP invaded_at, DROP invaded_in_war');
    }
}
