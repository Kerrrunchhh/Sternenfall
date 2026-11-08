<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108151649 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trade posts buy resources: purchase price and remaining amount per resource';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // JSON columns can't have a default: add them, fill existing posts, then make them required.
        $this->addSql('ALTER TABLE trade_post ADD buy_prices JSON DEFAULT NULL, ADD buy_limits JSON DEFAULT NULL');
        $this->addSql("UPDATE trade_post SET buy_prices = JSON_OBJECT(), buy_limits = JSON_OBJECT()");
        $this->addSql('ALTER TABLE trade_post MODIFY buy_prices JSON NOT NULL, MODIFY buy_limits JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trade_post DROP buy_prices, DROP buy_limits');
    }
}
