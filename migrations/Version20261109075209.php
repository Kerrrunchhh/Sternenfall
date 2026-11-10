<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109075209 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Debris fields known per player';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet_intel ADD debris_iron DOUBLE PRECISION DEFAULT NULL, ADD debris_titanium DOUBLE PRECISION DEFAULT NULL, ADD debris_seen_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet_intel DROP debris_iron, DROP debris_titanium, DROP debris_seen_at');
    }
}
