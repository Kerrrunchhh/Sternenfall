<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Message folders (battle reports, espionage, general); existing messages are sorted by their subject. */
final class Version20261119131147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Message folders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE message ADD folder VARCHAR(10) DEFAULT \'general\' NOT NULL');
        $this->addSql('CREATE INDEX idx_message_folder ON message (recipient_id, folder)');
        // Existing messages: battle reports and spy messages by their subject (German or English).
        $this->addSql("UPDATE message SET folder = 'battle' WHERE subject LIKE 'Kampfbericht%' OR subject LIKE 'Battle report%'");
        $this->addSql("UPDATE message SET folder = 'spy' WHERE subject LIKE 'Spionagebericht%' OR subject LIKE 'Spy report%' OR subject LIKE 'Ausspioniert%' OR subject LIKE 'Spied on%' OR subject LIKE 'Sonde bei%' OR subject LIKE 'Probe at%' OR (subject LIKE 'Flotte von%' AND subject LIKE '%ausgespäht') OR (subject LIKE 'Fleet of%' AND subject LIKE '%spied on')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_message_folder ON message');
        $this->addSql('ALTER TABLE message DROP folder');
    }
}
