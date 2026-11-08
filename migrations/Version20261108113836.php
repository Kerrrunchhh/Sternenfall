<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261108113836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Existing players get the colonization level their planet slots already need';
    }

    public function up(Schema $schema): void
    {
        // Colonization research now limits the planet slots (level + 1); nobody loses planets.
        $this->addSql("UPDATE `user` u JOIN (SELECT owner_id, SUM(fusion_size) AS slots FROM planet GROUP BY owner_id) p ON p.owner_id = u.id SET u.research = JSON_SET(u.research, '$.colonization', LEAST(50, p.slots - 1)) WHERE p.slots > 1");

    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE `user` SET research = JSON_REMOVE(research, '$.colonization') WHERE JSON_CONTAINS_PATH(research, 'one', '$.colonization')");

    }
}
