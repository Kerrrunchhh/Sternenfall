<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112192450 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trade posts stand on a planet of their owner';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trade_post ADD planet_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE trade_post ADD CONSTRAINT FK_38711B76A25E9820 FOREIGN KEY (planet_id) REFERENCES planet (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_38711B76A25E9820 ON trade_post (planet_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trade_post DROP FOREIGN KEY FK_38711B76A25E9820');
        $this->addSql('DROP INDEX IDX_38711B76A25E9820 ON trade_post');
        $this->addSql('ALTER TABLE trade_post DROP planet_id');
    }
}
