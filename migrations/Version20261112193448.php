<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112193448 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'News in German and English; English text of the welcome message';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE news ADD title_en VARCHAR(120) DEFAULT NULL, ADD body_en LONGTEXT DEFAULT NULL');
        // The first welcome message (as the migration of 12 Nov 2026 created it) gets its English text.
        $this->addSql("UPDATE news SET title_en = ?, body_en = ? WHERE welcome = 1 AND title = ?", ['Welcome to Starfall: New Horizons!', "Great to have you here! A few tips for the start:\n\n🚀 Starter bonus: on your first day everything builds and researches twice as fast, and your planets yield twice as many resources, credits and inhabitants. Make use of it!\n\n🛡 Newbie protection: as long as you have only one planet and no fleet control center, nobody may attack you, station ships with you or send you resources.\n\n🏗 First steps: mines, hydroponic farm and water pump provide resources and supplies, the command center speeds up all construction. With a shipyard and a fleet control center you can form and command fleets.\n\n📦 Short of resources? Post a transport request – traders (or the trade guild) deliver them for a fixed price in credits.\n\n❓ Questions or found a bug? Write to us under “Help & reports”.\n\nHave fun and a good journey through the stars!", 'Willkommen bei Starfall: New Horizons!']);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE news DROP title_en, DROP body_en');
    }
}
