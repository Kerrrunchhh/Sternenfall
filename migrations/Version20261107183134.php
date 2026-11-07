<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107183134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persistent named fleets with alliance release';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fleet_release (fleet_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_B81538164B061DF9 (fleet_id), INDEX IDX_B8153816A76ED395 (user_id), PRIMARY KEY (fleet_id, user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fleet_release ADD CONSTRAINT FK_B81538164B061DF9 FOREIGN KEY (fleet_id) REFERENCES fleet (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fleet_release ADD CONSTRAINT FK_B8153816A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fleet DROP FOREIGN KEY `FK_A05E1E4756A273CC`');
        $this->addSql('DROP INDEX IDX_A05E1E4756A273CC ON fleet');
        $this->addSql('ALTER TABLE fleet ADD name VARCHAR(40) DEFAULT NULL, ADD galaxy SMALLINT NOT NULL, ADD solar_system SMALLINT NOT NULL, ADD position SMALLINT NOT NULL, ADD released_to_alliance TINYINT DEFAULT 0 NOT NULL, ADD target_fleet_id INT DEFAULT NULL, DROP origin_id, CHANGE mission mission VARCHAR(255) DEFAULT NULL, CHANGE target_galaxy target_galaxy SMALLINT DEFAULT NULL, CHANGE target_system target_system SMALLINT DEFAULT NULL, CHANGE target_position target_position SMALLINT DEFAULT NULL, CHANGE depart_at depart_at DATETIME DEFAULT NULL, CHANGE arrive_at arrive_at DATETIME DEFAULT NULL, CHANGE return_at return_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE fleet ADD CONSTRAINT FK_A05E1E4791ADCD90 FOREIGN KEY (target_fleet_id) REFERENCES fleet (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_A05E1E4791ADCD90 ON fleet (target_fleet_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fleet_release DROP FOREIGN KEY FK_B81538164B061DF9');
        $this->addSql('ALTER TABLE fleet_release DROP FOREIGN KEY FK_B8153816A76ED395');
        $this->addSql('DROP TABLE fleet_release');
        $this->addSql('ALTER TABLE fleet DROP FOREIGN KEY FK_A05E1E4791ADCD90');
        $this->addSql('DROP INDEX IDX_A05E1E4791ADCD90 ON fleet');
        $this->addSql('ALTER TABLE fleet ADD origin_id INT NOT NULL, DROP name, DROP galaxy, DROP solar_system, DROP position, DROP released_to_alliance, DROP target_fleet_id, CHANGE mission mission VARCHAR(255) NOT NULL, CHANGE target_galaxy target_galaxy SMALLINT NOT NULL, CHANGE target_system target_system SMALLINT NOT NULL, CHANGE target_position target_position SMALLINT NOT NULL, CHANGE depart_at depart_at DATETIME NOT NULL, CHANGE arrive_at arrive_at DATETIME NOT NULL, CHANGE return_at return_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE fleet ADD CONSTRAINT `FK_A05E1E4756A273CC` FOREIGN KEY (origin_id) REFERENCES planet (id) ON UPDATE NO ACTION ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_A05E1E4756A273CC ON fleet (origin_id)');
    }
}
