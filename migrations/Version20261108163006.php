<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108163006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Squadrons: named groups of fleets';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE squadron (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(40) NOT NULL, owner_id INT NOT NULL, INDEX IDX_5901B7577E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE squadron_fleet (squadron_id INT NOT NULL, fleet_id INT NOT NULL, INDEX IDX_42DB6CCAD331F5B5 (squadron_id), INDEX IDX_42DB6CCA4B061DF9 (fleet_id), PRIMARY KEY (squadron_id, fleet_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE squadron ADD CONSTRAINT FK_5901B7577E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE squadron_fleet ADD CONSTRAINT FK_42DB6CCAD331F5B5 FOREIGN KEY (squadron_id) REFERENCES squadron (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE squadron_fleet ADD CONSTRAINT FK_42DB6CCA4B061DF9 FOREIGN KEY (fleet_id) REFERENCES fleet (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE squadron DROP FOREIGN KEY FK_5901B7577E3C61F9');
        $this->addSql('ALTER TABLE squadron_fleet DROP FOREIGN KEY FK_42DB6CCAD331F5B5');
        $this->addSql('ALTER TABLE squadron_fleet DROP FOREIGN KEY FK_42DB6CCA4B061DF9');
        $this->addSql('DROP TABLE squadron');
        $this->addSql('DROP TABLE squadron_fleet');
    }
}
