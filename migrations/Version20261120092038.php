<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Hourly highscore snapshots for the changes of place in 24 hours. */
final class Version20261120092038 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hourly highscore snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE highscore_snapshot (id INT AUTO_INCREMENT NOT NULL, taken_at DATETIME NOT NULL, players JSON NOT NULL, UNIQUE INDEX uniq_highscore_snapshot_taken (taken_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE highscore_snapshot');
    }
}
