<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107173742 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Planet fusion';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet ADD fusion_size SMALLINT DEFAULT 1 NOT NULL, ADD fused_planets JSON DEFAULT NULL');
        $this->addSql("UPDATE planet SET fused_planets = '[]'");
        $this->addSql('ALTER TABLE planet MODIFY fused_planets JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE planet DROP fusion_size, DROP fused_planets');
    }
}
