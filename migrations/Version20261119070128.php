<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Own order for remembered coordinates; existing ones start in their old coordinate order. */
final class Version20261119070128 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bookmarks: own sort order per player';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookmark ADD sort_order INT DEFAULT 0 NOT NULL');
        $this->addSql('UPDATE bookmark b JOIN (SELECT id, ROW_NUMBER() OVER (PARTITION BY owner_id ORDER BY galaxy, solar_system, position) AS n FROM bookmark) o ON o.id = b.id SET b.sort_order = o.n');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bookmark DROP sort_order');
    }
}
