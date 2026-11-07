<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261107190031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trade posts';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE trade_post (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(40) NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, prices JSON NOT NULL, stock JSON NOT NULL, allowed_life_forms JSON NOT NULL, created_at DATETIME NOT NULL, owner_id INT NOT NULL, UNIQUE INDEX uniq_trade_post_slot (galaxy, solar_system, position), INDEX IDX_38711B767E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE trade_post_alliance (trade_post_id INT NOT NULL, alliance_id INT NOT NULL, INDEX IDX_4224D422B8095BDA (trade_post_id), INDEX IDX_4224D42210A0EA3F (alliance_id), PRIMARY KEY (trade_post_id, alliance_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE trade_post ADD CONSTRAINT FK_38711B767E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trade_post_alliance ADD CONSTRAINT FK_4224D422B8095BDA FOREIGN KEY (trade_post_id) REFERENCES trade_post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE trade_post_alliance ADD CONSTRAINT FK_4224D42210A0EA3F FOREIGN KEY (alliance_id) REFERENCES alliance (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fleet ADD trade_order JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trade_post DROP FOREIGN KEY FK_38711B767E3C61F9');
        $this->addSql('ALTER TABLE trade_post_alliance DROP FOREIGN KEY FK_4224D422B8095BDA');
        $this->addSql('ALTER TABLE trade_post_alliance DROP FOREIGN KEY FK_4224D42210A0EA3F');
        $this->addSql('DROP TABLE trade_post');
        $this->addSql('DROP TABLE trade_post_alliance');
        $this->addSql('ALTER TABLE fleet DROP trade_order');
    }
}
