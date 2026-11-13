<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113072710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'News remember when they were mailed; mail topic "news" on for everyone';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE news ADD mailed_at DATETIME DEFAULT NULL');
        // News published before are not mailed afterwards.
        $this->addSql('UPDATE news SET mailed_at = UTC_TIMESTAMP()');
        // Players who already saved their mail settings get the new topic "news" switched on too.
        $this->addSql("UPDATE `user` SET mail_topics = JSON_ARRAY_APPEND(mail_topics, '$', 'news') WHERE mail_topics IS NOT NULL AND NOT JSON_CONTAINS(mail_topics, '\"news\"')");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE news DROP mailed_at');
    }
}
