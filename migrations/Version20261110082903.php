<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261110082903 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E-mail address, confirmation and notification settings';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user ADD email VARCHAR(180) DEFAULT NULL, ADD email_verified_at DATETIME DEFAULT NULL, ADD mail_topics JSON DEFAULT NULL, ADD research_mail_sent TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON user (email)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX UNIQ_8D93D649E7927C74 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP email, DROP email_verified_at, DROP mail_topics, DROP research_mail_sent');
    }
}
