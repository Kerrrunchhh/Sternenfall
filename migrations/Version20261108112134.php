<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108112134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fusionator market: offers of traders with price and buyer restrictions';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE fusionator_offer (id INT AUTO_INCREMENT NOT NULL, galaxy SMALLINT NOT NULL, solar_system SMALLINT NOT NULL, position SMALLINT NOT NULL, quantity INT NOT NULL, price DOUBLE PRECISION NOT NULL, allowed_life_forms JSON NOT NULL, created_at DATETIME NOT NULL, seller_id INT NOT NULL, INDEX IDX_7C687FD8DE820D9 (seller_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE fusionator_offer_alliance (fusionator_offer_id INT NOT NULL, alliance_id INT NOT NULL, INDEX IDX_CEF52F58DD984CBD (fusionator_offer_id), INDEX IDX_CEF52F5810A0EA3F (alliance_id), PRIMARY KEY (fusionator_offer_id, alliance_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE fusionator_offer ADD CONSTRAINT FK_7C687FD8DE820D9 FOREIGN KEY (seller_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fusionator_offer_alliance ADD CONSTRAINT FK_CEF52F58DD984CBD FOREIGN KEY (fusionator_offer_id) REFERENCES fusionator_offer (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fusionator_offer_alliance ADD CONSTRAINT FK_CEF52F5810A0EA3F FOREIGN KEY (alliance_id) REFERENCES alliance (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE fusionator_offer DROP FOREIGN KEY FK_7C687FD8DE820D9');
        $this->addSql('ALTER TABLE fusionator_offer_alliance DROP FOREIGN KEY FK_CEF52F58DD984CBD');
        $this->addSql('ALTER TABLE fusionator_offer_alliance DROP FOREIGN KEY FK_CEF52F5810A0EA3F');
        $this->addSql('DROP TABLE fusionator_offer');
        $this->addSql('DROP TABLE fusionator_offer_alliance');
    }
}
