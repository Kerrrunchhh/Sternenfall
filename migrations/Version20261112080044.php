<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112080044 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'News for all players (with read marks) and a first welcome message';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE news (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(120) NOT NULL, body LONGTEXT NOT NULL, welcome TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, INDEX idx_news_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE news_read (id INT AUTO_INCREMENT NOT NULL, read_at DATETIME NOT NULL, news_id INT NOT NULL, user_id INT NOT NULL, UNIQUE INDEX uniq_news_read (news_id, user_id), INDEX IDX_4D8AB7ECB5A459A0 (news_id), INDEX IDX_4D8AB7ECA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE news_read ADD CONSTRAINT FK_4D8AB7ECB5A459A0 FOREIGN KEY (news_id) REFERENCES news (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE news_read ADD CONSTRAINT FK_4D8AB7ECA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        // A first welcome message; admins can edit it under "Neuigkeiten".
        $this->addSql('INSERT INTO news (title, body, welcome, created_at) VALUES (?, ?, 1, UTC_TIMESTAMP())', ['Willkommen bei Starfall: New Horizons!', "Schön, dass du da bist! Ein paar Tipps für den Start:\n\n🚀 Startbonus: An deinem ersten Tag baut und forscht alles doppelt so schnell, und deine Planeten liefern doppelt so viele Rohstoffe, Credits und Einwohner. Nutze ihn!\n\n🛡 Noobschutz: Solange du nur einen Planeten und kein Flottenkontrollzentrum hast, darf dich niemand angreifen, bei dir stationieren oder dir Rohstoffe schicken.\n\n🏗 Erste Schritte: Minen, Hydrokulturfarm und Wasserpumpwerk sorgen für Rohstoffe und Versorgung, die Kommandozentrale beschleunigt alle Bauten. Mit Raumwerft und Flottenkontrollzentrum kannst du Flotten bilden und befehligen.\n\n📦 Fehlen dir Rohstoffe? Stelle ein Transportgesuch – Händler liefern sie dir zum festen Preis in Credits.\n\n❓ Fragen oder einen Fehler gefunden? Schreib uns unter „Hilfe & Meldungen“.\n\nViel Spaß und gute Reise durch die Sterne!"]);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE news_read DROP FOREIGN KEY FK_4D8AB7ECB5A459A0');
        $this->addSql('ALTER TABLE news_read DROP FOREIGN KEY FK_4D8AB7ECA76ED395');
        $this->addSql('DROP TABLE news');
        $this->addSql('DROP TABLE news_read');
    }
}
