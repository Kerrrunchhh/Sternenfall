<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108154934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trade posts no longer deal in fusionators: stocked ones go back to the owner\'s home planet';
    }

    public function up(Schema $schema): void
    {
        // Fusionators in trade post stock go back into the hangar of the owner's home planet.
        $this->addSql("UPDATE planet p JOIN (SELECT owner_id, SUM(JSON_EXTRACT(stock, '$.fusionator')) AS n FROM trade_post WHERE JSON_EXTRACT(stock, '$.fusionator') > 0 GROUP BY owner_id) t ON t.owner_id = p.owner_id AND p.homeworld = 1 SET p.ships = JSON_SET(p.ships, '$.fusionator', COALESCE(JSON_EXTRACT(p.ships, '$.fusionator'), 0) + t.n)");
        $this->addSql("UPDATE trade_post SET stock = JSON_REMOVE(stock, '$.fusionator') WHERE JSON_CONTAINS_PATH(stock, 'one', '$.fusionator')");
        $this->addSql("UPDATE trade_post SET prices = JSON_REMOVE(prices, '$.fusionator') WHERE JSON_CONTAINS_PATH(prices, 'one', '$.fusionator')");

    }

    public function down(Schema $schema): void
    {
        // Nothing to restore: the fusionators stay in the hangar.

    }
}
