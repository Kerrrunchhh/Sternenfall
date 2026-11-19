<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Admin notices get a detail text (PayPal problems: what happened and the browser). */
final class Version20261119155550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin notices: detail text';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_notice ADD detail LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_notice DROP detail');
    }
}
