<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107175611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Life forms and credits';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shipyard_order ADD unit_cost JSON DEFAULT NULL, ADD unit_credits DOUBLE PRECISION DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE shipyard_order SET unit_cost = '{}'");
        $this->addSql('ALTER TABLE shipyard_order MODIFY unit_cost JSON NOT NULL');
        $this->addSql('ALTER TABLE `user` ADD life_form VARCHAR(255) DEFAULT \'settler\' NOT NULL, ADD life_form_since DATETIME DEFAULT NULL, ADD credits DOUBLE PRECISION DEFAULT 0 NOT NULL');
        // Everybody starts as settler (since registration) with the same start credits as new players.
        $this->addSql('UPDATE `user` SET life_form_since = created_at, credits = 1000');
        $this->addSql('ALTER TABLE `user` MODIFY life_form_since DATETIME NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shipyard_order DROP unit_cost, DROP unit_credits');
        $this->addSql('ALTER TABLE `user` DROP life_form, DROP life_form_since, DROP credits');
    }
}
