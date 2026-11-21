<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Invaded planets remember their original owner while they integrate. */
final class Version20261121070802 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Original owner of invaded planets';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planet ADD original_owner_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE planet ADD CONSTRAINT FK_68136AA55D6386E8 FOREIGN KEY (original_owner_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_68136AA55D6386E8 ON planet (original_owner_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE planet DROP FOREIGN KEY FK_68136AA55D6386E8');
        $this->addSql('DROP INDEX IDX_68136AA55D6386E8 ON planet');
        $this->addSql('ALTER TABLE planet DROP original_owner_id');
    }
}
