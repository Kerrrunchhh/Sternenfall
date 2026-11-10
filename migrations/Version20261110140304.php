<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110140304 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Research runs on its slot; attack mail limit';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // Keep running research: its planet becomes its slot.
        $this->addSql('ALTER TABLE user ADD research_slot VARCHAR(20) DEFAULT NULL');
        $this->addSql("UPDATE user u JOIN planet p ON p.id = u.research_planet_id SET u.research_slot = CONCAT(p.galaxy, ':', p.solar_system, ':', p.position)");
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY `FK_8D93D649D91248D9`');
        $this->addSql('DROP INDEX IDX_8D93D649D91248D9 ON user');
        $this->addSql('ALTER TABLE user ADD attack_mails_per_minute INT DEFAULT 3 NOT NULL, ADD attack_mail_times JSON DEFAULT NULL, DROP research_planet_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE `user` ADD research_planet_id INT DEFAULT NULL, DROP attack_mails_per_minute, DROP attack_mail_times, DROP research_slot');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT `FK_8D93D649D91248D9` FOREIGN KEY (research_planet_id) REFERENCES planet (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649D91248D9 ON `user` (research_planet_id)');
    }
}
