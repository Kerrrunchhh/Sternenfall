<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108161640 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Colonization levels now give 10 planet slots each: level n+1 slots becomes ceil((n+1)/10)';
    }

    public function up(Schema $schema): void
    {
        // Old: level n allowed n + 1 slots. New: level n allows 10·n. Nobody loses slots.
        $this->addSql("UPDATE `user` SET research = JSON_SET(JSON_REMOVE(research, '$.colonization'), '$.colonization', CAST(CEIL((JSON_EXTRACT(research, '$.colonization') + 1) / 10) AS UNSIGNED)) WHERE JSON_EXTRACT(research, '$.colonization') > 0");

    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE `user` SET research = JSON_SET(JSON_REMOVE(research, '$.colonization'), '$.colonization', CAST(JSON_EXTRACT(research, '$.colonization') * 10 - 1 AS UNSIGNED)) WHERE JSON_EXTRACT(research, '$.colonization') > 0");

    }
}
